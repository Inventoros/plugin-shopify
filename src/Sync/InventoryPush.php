<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Sync;

use App\Services\ProductLocationStockService;
use App\Services\WarehouseStockLevelService;
use Illuminate\Support\Collection;
use Inventoros\Plugins\Shopify\Kit\Mapping\MappingStore;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Kit\Models\OutboxEntry;
use Inventoros\Plugins\Shopify\Kit\Models\SyncRun;
use Inventoros\Plugins\Shopify\Kit\Outbox\Outbox;
use Inventoros\Plugins\Shopify\Kit\Sync\SyncRecorder;
use Inventoros\Plugins\Shopify\Shopify\ClientFactory;
use Inventoros\Plugins\Shopify\Support\Core;

/**
 * Pushes Inventoros stock to Shopify as absolute "available" quantities.
 *
 * The outbox holds one row per changed item ("p{product id}" or
 * "v{variant id}"), however many times it changed. A push reads the
 * CURRENT quantities (never a stale delta), sends them in batches of 250,
 * and clears the rows it pushed. Absolute values make a retried or
 * duplicated push harmless.
 *
 * Quantity per mapped location:
 *   product, warehouse location   the product's bins in that warehouse
 *   product, "all stock" location the product's total stock
 *   product without any bins      its total, at the primary location only
 *   variant                       the variant's stock, at the primary
 *                                 location only (core keeps variant stock
 *                                 without a location breakdown)
 * Negative stock is pushed as 0.
 */
final class InventoryPush
{
    public const KIND = 'inventory';

    private const BATCH = 250;

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly MappingStore $mappings,
        private readonly Outbox $outbox,
        private readonly Locations $locations,
        private readonly SyncRecorder $recorder,
        private readonly WarehouseStockLevelService $warehouseLevels,
        private readonly ProductLocationStockService $bins,
    ) {}

    public static function subject(string $internalType, int $internalId): string
    {
        return ($internalType === 'variant' ? 'v' : 'p').$internalId;
    }

    /**
     * Queue every mapped item for a full push (initial or manual resync).
     */
    public function queueAll(Connection $connection): int
    {
        $count = 0;

        foreach ($this->mappings->mapped($connection, Catalog::ENTITY) as $mapping) {
            $this->outbox->put($connection, self::KIND, self::subject((string) $mapping->internal_type, (int) $mapping->internal_id));
            $count++;
        }

        return $count;
    }

    /**
     * Push the given outbox rows.
     *
     * @param  Collection<int, OutboxEntry>  $entries
     */
    public function push(Connection $connection, Collection $entries, SyncRun $run): void
    {
        $locations = $this->locations->mapped($connection);
        $primary = $this->locations->primary($connection->id);
        $quantities = [];
        $lines = [];

        foreach ($entries as $entry) {
            $rows = $this->quantitiesFor($connection, $entry->subject, $locations, $primary);

            foreach ($rows as $row) {
                $quantities[] = ['inventory_item_id' => $row['inventory_item_id'], 'location_id' => $row['location_id'], 'quantity' => $row['quantity']];
                $lines[] = $row;
            }

            if ($rows === []) {
                $this->recorder->count($run, 'skipped');
            }
        }

        $client = $quantities === [] ? null : $this->clients->make($connection);
        $signature = $entries->map(fn (OutboxEntry $entry) => $entry->id.':'.$entry->coalesced)->sort()->implode(',');

        foreach (array_chunk($quantities, self::BATCH) as $batch) {
            $client->setInventoryQuantities($batch, $this->idempotencyKey($connection, $batch, $signature));
        }

        foreach ($lines as $line) {
            $this->recorder->count($run, 'processed');
            $this->recorder->count($run, 'updated');
            $this->recorder->log($connection, $run, 'info', "Set {$line['label']} to {$line['quantity']} at {$line['location_label']}.", [
                'entity' => Catalog::ENTITY,
                'external_id' => $line['variant_id'],
                'internal_id' => $line['internal_id'],
                'action' => 'push_inventory',
                'context' => ['location_id' => $line['location_id'], 'quantity' => $line['quantity']],
            ]);
        }

        foreach ($entries as $entry) {
            $this->outbox->done($entry);
        }
    }

    /**
     * @param  Collection<int, Mapping>  $locations
     * @return array<int, array{inventory_item_id: string, location_id: string, quantity: int, label: string, location_label: string, variant_id: string, internal_id: int}>
     */
    public function quantitiesFor(Connection $connection, string $subject, Collection $locations, ?Mapping $primary): array
    {
        $type = str_starts_with($subject, 'v') ? 'variant' : 'product';
        $id = (int) substr($subject, 1);
        $mapping = $this->mappings->forInternal($connection, Catalog::ENTITY, $type, $id);

        if ($mapping === null || $mapping->meta('inventory_item_id') === null || $locations->isEmpty()) {
            return [];
        }

        $rows = [];
        $base = [
            'inventory_item_id' => (string) $mapping->meta('inventory_item_id'),
            'label' => (string) ($mapping->external_label ?? $mapping->sku ?? $mapping->external_id),
            'variant_id' => $mapping->external_id,
            'internal_id' => $id,
        ];

        if ($type === 'variant') {
            $variant = Core::variants($connection->organization_id)->find($id);

            if ($variant === null || $primary === null) {
                return [];
            }

            return [$base + ['location_id' => $primary->external_id, 'location_label' => (string) $primary->external_label, 'quantity' => max(0, (int) $variant->stock)]];
        }

        $product = Core::products($connection->organization_id)->find($id);

        if ($product === null) {
            return [];
        }

        $binned = $this->bins->totalAssigned($product) > 0;

        foreach ($locations as $location) {
            if ($location->internal_type === Locations::ALL_STOCK) {
                $quantity = (int) $product->stock;
            } elseif (! $binned) {
                if ($primary === null || $location->id !== $primary->id) {
                    continue;
                }
                $quantity = (int) $product->stock;
            } else {
                $quantity = $this->warehouseLevels->onHandInWarehouse($product->id, (int) $location->internal_id);
            }

            $rows[] = $base + ['location_id' => $location->external_id, 'location_label' => (string) $location->external_label, 'quantity' => max(0, $quantity)];
        }

        return $rows;
    }

    /**
     * The same for a retry of the same push (same outbox rows, same
     * quantities), so Shopify applies it once; different for any later push,
     * so a repeated quantity is still applied after Shopify drifted.
     *
     * @param  array<int, array{inventory_item_id: string, location_id: string, quantity: int}>  $batch
     */
    private function idempotencyKey(Connection $connection, array $batch, string $signature): string
    {
        usort($batch, fn ($a, $b) => [$a['inventory_item_id'], $a['location_id']] <=> [$b['inventory_item_id'], $b['location_id']]);

        return 'inventoros-'.$connection->id.'-'.substr(hash('sha256', $signature.'|'.json_encode($batch)), 0, 40);
    }
}
