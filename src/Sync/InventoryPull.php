<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Sync;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Services\WarehouseStockLevelService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Kit\Mapping\MappingStore;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Kit\Models\SyncRun;
use Inventoros\Plugins\Shopify\Kit\Sync\SyncRecorder;
use Inventoros\Plugins\Shopify\Support\Core;

/**
 * Applies Shopify's inventory levels to Inventoros when Shopify owns stock.
 *
 * The difference between Shopify's available quantity and Inventoros'
 * quantity at the mapped warehouse becomes one audited stock adjustment
 * (type "correction") through core's StockAdjustment::adjust() or
 * adjustVariant(), under a row lock, attributed to the connection's user.
 * Stock is never written directly. A level older than one already applied
 * for the same item and location is ignored (out-of-order webhooks).
 */
final class InventoryPull
{
    public const ADJUSTMENT_TYPE = 'correction';

    public function __construct(
        private readonly MappingStore $mappings,
        private readonly Locations $locations,
        private readonly SyncRecorder $recorder,
        private readonly WarehouseStockLevelService $warehouseLevels,
    ) {}

    /**
     * @return string applied, unchanged, stale or skipped
     */
    public function apply(Connection $connection, string $inventoryItemId, string $locationId, int $available, ?string $updatedAt, ?SyncRun $run): string
    {
        $item = Mapping::query()
            ->where('connection_id', $connection->id)
            ->where('entity', Catalog::ENTITY)
            ->where('status', Mapping::MAPPED)
            ->where('meta->inventory_item_id', $inventoryItemId)
            ->first();

        $location = $this->mappings->find($connection, Locations::ENTITY, $locationId);

        if ($item === null || $location === null || ! $location->isMapped()) {
            return 'skipped';
        }

        $at = $updatedAt === null ? null : Carbon::parse($updatedAt);
        $levels = (array) $item->meta('levels_at', []);
        $applied = isset($levels[$locationId]) ? Carbon::parse($levels[$locationId]) : null;

        if ($at !== null && $applied !== null && $at->lt($applied)) {
            $this->recorder->log($connection, $run, 'info', "Ignored an older inventory level for {$item->external_label} at {$location->external_label}.", [
                'entity' => Catalog::ENTITY, 'external_id' => $item->external_id, 'action' => 'stale',
            ]);

            return 'stale';
        }

        $actor = Core::actor($connection);
        $primary = $this->locations->primary($connection->id);
        $reason = 'Shopify inventory sync';
        $notes = "Shopify location {$location->external_label}, available {$available}";

        $outcome = DB::transaction(function () use ($connection, $item, $location, $primary, $available, $actor, $reason, $notes) {
            if ($item->internal_type === 'variant') {
                if ($primary === null || $location->id !== $primary->id) {
                    return 'skipped';
                }

                $variant = Core::variants($connection->organization_id)->lockForUpdate()->find($item->internal_id);
                if (! $variant instanceof ProductVariant) {
                    return 'skipped';
                }

                $delta = $available - (int) $variant->stock;
                if ($delta === 0) {
                    return 'unchanged';
                }

                StockAdjustment::adjustVariant($variant, $delta, self::ADJUSTMENT_TYPE, $reason, $notes, null, true, $actor);

                return 'applied';
            }

            $product = Core::products($connection->organization_id)->lockForUpdate()->find($item->internal_id);
            if (! $product instanceof Product) {
                return 'skipped';
            }

            if ($location->internal_type === Locations::ALL_STOCK) {
                $delta = $available - (int) $product->stock;
                $bin = null;
            } else {
                $warehouseId = (int) $location->internal_id;
                $delta = $available - $this->warehouseLevels->onHandInWarehouse($product->id, $warehouseId);
                $bin = $this->binFor($connection, $product, $warehouseId, $location);
            }

            if ($delta === 0) {
                return 'unchanged';
            }

            StockAdjustment::adjust($product, $delta, self::ADJUSTMENT_TYPE, $reason, $notes, null, true, $actor, $bin);

            return 'applied';
        });

        $levels[$locationId] = ($at ?? now())->toIso8601String();
        $item->meta = array_merge($item->meta ?? [], ['levels_at' => $levels]);
        $item->synced_at = now();
        $item->save();

        if ($outcome === 'applied') {
            $this->recorder->log($connection, $run, 'info', "Set {$item->external_label} to {$available} at {$location->external_label} from Shopify.", [
                'entity' => Catalog::ENTITY,
                'external_id' => $item->external_id,
                'internal_id' => $item->internal_id,
                'action' => 'pull_inventory',
            ]);
        }

        return $outcome;
    }

    /**
     * The bin an adjustment in a warehouse lands in: the location
     * mapping's chosen bin, else the bin in that warehouse that already
     * holds the product, else the warehouse's first active location.
     */
    private function binFor(Connection $connection, Product $product, int $warehouseId, Mapping $location): int
    {
        $chosen = $location->meta('bin_id');
        if ($chosen !== null && Core::locations($connection->organization_id)->where('warehouse_id', $warehouseId)->whereKey($chosen)->exists()) {
            return (int) $chosen;
        }

        $holding = DB::table('product_location_stocks')
            ->join('product_locations', 'product_locations.id', '=', 'product_location_stocks.location_id')
            ->where('product_location_stocks.product_id', $product->id)
            ->where('product_locations.warehouse_id', $warehouseId)
            ->whereNull('product_locations.deleted_at')
            ->orderByDesc('product_location_stocks.quantity')
            ->value('product_location_stocks.location_id');

        if ($holding !== null) {
            return (int) $holding;
        }

        $first = Core::locations($connection->organization_id)
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        if ($first === null) {
            throw new PermanentError("The warehouse mapped to {$location->external_label} has no locations to put stock in. Add a location to it, then retry.");
        }

        return (int) $first;
    }
}
