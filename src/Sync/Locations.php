<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Sync;

use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inventoros\Plugins\Shopify\Kit\Mapping\MappingStore;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Kit\Models\SyncRun;
use Inventoros\Plugins\Shopify\Kit\Sync\SyncRecorder;
use Inventoros\Plugins\Shopify\Shopify\ClientFactory;

/**
 * Shopify locations and the Inventoros warehouses they stand for.
 *
 * A location maps to one warehouse (its quantity is the product's bins in
 * that warehouse) or to "all stock" (the product's total). One mapped
 * location is primary: variant stock (which core keeps without a location
 * breakdown) is pushed there, and imported orders are placed in its
 * warehouse.
 */
final class Locations
{
    public const ENTITY = 'location';

    /** internal_type of a location mapped to the whole organization's stock. */
    public const ALL_STOCK = 'organization';

    public const WAREHOUSE = 'warehouse';

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly MappingStore $mappings,
        private readonly SyncRecorder $recorder,
    ) {}

    public function sync(Connection $connection, SyncRun $run): void
    {
        $client = $this->clients->make($connection);
        $after = null;

        do {
            $page = $client->locations($after);

            foreach ($page->items as $location) {
                $existing = $this->mappings->find($connection, self::ENTITY, $location['id']);
                $this->mappings->remember($connection, self::ENTITY, $location['id'], [
                    'external_label' => $location['name'],
                    'meta' => ['active' => $location['active']],
                ]);
                $this->recorder->count($run, 'processed');
                $this->recorder->count($run, $existing === null ? 'created' : 'updated');
            }

            $after = $page->endCursor;
        } while ($page->hasNextPage && $after !== null);

        $this->autoMapSingle($connection, $run);
    }

    /**
     * One Shopify location and one active warehouse: map them as primary,
     * since there is nothing to choose.
     */
    private function autoMapSingle(Connection $connection, SyncRun $run): void
    {
        $locations = Mapping::query()->where('connection_id', $connection->id)->where('entity', self::ENTITY)->get();

        if ($locations->count() !== 1 || $locations->first()->isMapped()) {
            return;
        }

        $warehouses = Warehouse::query()
            ->where('organization_id', $connection->organization_id)
            ->where('is_active', true)
            ->pluck('id');

        if ($warehouses->count() !== 1) {
            return;
        }

        $this->map($locations->first(), self::WAREHOUSE, (int) $warehouses->first(), true);
        $this->recorder->log($connection, $run, 'info', "Mapped the only Shopify location ({$locations->first()->external_label}) to the only warehouse.", [
            'entity' => self::ENTITY,
            'external_id' => $locations->first()->external_id,
            'action' => 'auto_map',
        ]);
    }

    /**
     * Map a location to a warehouse (or ALL_STOCK with the organization id),
     * optionally as the primary location.
     */
    public function map(Mapping $location, string $type, int $id, bool $primary): Mapping
    {
        return DB::transaction(function () use ($location, $type, $id, $primary) {
            $this->mappings->link($location, $type, $id);

            $hasPrimary = $this->primary($location->connection_id) !== null;

            if ($primary || ! $hasPrimary) {
                $this->setPrimary($location);
            }

            return $location->refresh();
        });
    }

    public function setPrimary(Mapping $location): void
    {
        Mapping::query()
            ->where('connection_id', $location->connection_id)
            ->where('entity', self::ENTITY)
            ->get()
            ->each(function (Mapping $row) use ($location) {
                $meta = $row->meta ?? [];
                $meta['primary'] = $row->id === $location->id;
                $row->meta = $meta;
                $row->save();
            });
    }

    public function primary(int $connectionId): ?Mapping
    {
        return Mapping::query()
            ->where('connection_id', $connectionId)
            ->where('entity', self::ENTITY)
            ->where('status', Mapping::MAPPED)
            ->get()
            ->first(fn (Mapping $row) => (bool) $row->meta('primary'));
    }

    /**
     * @return Collection<int, Mapping>
     */
    public function mapped(Connection $connection): Collection
    {
        return $this->mappings->mapped($connection, self::ENTITY);
    }

    /**
     * The warehouse imported orders go to: the primary location's.
     */
    public function orderWarehouseId(Connection $connection): ?int
    {
        $primary = $this->primary($connection->id);

        return $primary !== null && $primary->internal_type === self::WAREHOUSE ? $primary->internal_id : null;
    }
}
