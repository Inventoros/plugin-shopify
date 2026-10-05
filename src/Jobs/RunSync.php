<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Jobs;

use Illuminate\Support\Carbon;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Kit\Jobs\DeadLetterQueue;
use Inventoros\Plugins\Shopify\Kit\Jobs\IntegrationJob;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\SyncRun;
use Inventoros\Plugins\Shopify\Kit\Outbox\Outbox;
use Inventoros\Plugins\Shopify\Kit\Sync\CursorStore;
use Inventoros\Plugins\Shopify\Kit\Sync\SyncRecorder;
use Inventoros\Plugins\Shopify\Shopify\ClientFactory;
use Inventoros\Plugins\Shopify\Shopify\SyncPolicy;
use Inventoros\Plugins\Shopify\Sync\Catalog;
use Inventoros\Plugins\Shopify\Sync\InventoryPull;
use Inventoros\Plugins\Shopify\Sync\InventoryPush;
use Inventoros\Plugins\Shopify\Sync\Locations;
use Inventoros\Plugins\Shopify\Sync\OrderImporter;
use Throwable;

/**
 * Initial, incremental and manual sync of one or more resources, in order:
 * locations, products, inventory, orders.
 *
 *  initial / manual  everything (orders since the connection's start date)
 *  incremental       products and orders changed since the stored cursor
 *                    (a safety net under webhooks; scheduled every 15 min)
 *
 * Pages are applied one at a time and the cursor advances after each, so
 * a run that stops resumes where it left off. After MAX_PAGES pages the
 * job queues its own continuation, so one job never runs for long.
 * An order that cannot be imported (unmapped SKU, not enough stock) fails
 * on its own: it is logged and dead-lettered as an ImportOrder job, and
 * the rest of the run continues (the run is then "partial").
 */
class RunSync extends IntegrationJob
{
    public const RESOURCES = ['locations', 'products', 'inventory', 'orders'];

    public const MODES = ['initial', 'incremental', 'manual'];

    private const MAX_PAGES = 20;

    /** Incremental reads start a little before the cursor, for clock skew. */
    private const OVERLAP_SECONDS = 120;

    /**
     * @param  array<int, string>  $resources
     */
    public function __construct(
        public int $connectionKey,
        public array $resources,
        public string $mode = 'incremental',
        public string $trigger = 'schedule',
        public ?int $userId = null,
        public ?string $after = null,
    ) {
        $this->resources = array_values(array_intersect(self::RESOURCES, $resources));
    }

    public function connectionId(): int
    {
        return $this->connectionKey;
    }

    public function arguments(): array
    {
        return [$this->connectionKey, $this->resources, $this->mode, 'retry', $this->userId, $this->after];
    }

    public function reference(): ?string
    {
        return $this->mode.' sync of '.implode(', ', $this->resources);
    }

    protected function perform(Connection $connection): void
    {
        $resource = $this->resources[0] ?? null;

        if ($resource === null) {
            return;
        }

        $recorder = app(SyncRecorder::class);
        $run = $recorder->start($connection, $this->mode, $resource, $this->trigger, $this->userId);

        try {
            $next = match ($resource) {
                'locations' => $this->locations($connection, $run),
                'products' => $this->products($connection, $run),
                'inventory' => $this->inventory($connection, $run),
                'orders' => $this->orders($connection, $run),
            };
        } catch (Throwable $error) {
            $recorder->finish($run, $error);

            throw $error;
        }

        $recorder->finish($run);

        if ($next !== null) {
            dispatch(new static($this->connectionKey, $this->resources, $this->mode, $this->trigger, $this->userId, $next));

            return;
        }

        $rest = array_slice($this->resources, 1);
        if ($rest !== []) {
            dispatch(new static($this->connectionKey, $rest, $this->mode, $this->trigger, $this->userId));
        }
    }

    private function locations(Connection $connection, SyncRun $run): ?string
    {
        app(Locations::class)->sync($connection, $run);

        return null;
    }

    /**
     * @return string|null the page cursor to continue from
     */
    private function products(Connection $connection, SyncRun $run): ?string
    {
        $client = app(ClientFactory::class)->make($connection);
        $cursors = app(CursorStore::class);
        $catalog = app(Catalog::class);
        $since = $this->mode === 'incremental' ? $this->since($cursors->position($connection, 'products')) : null;
        $after = $this->after;

        for ($pages = 0; $pages < self::MAX_PAGES; $pages++) {
            $page = $client->products($after, $since);
            $newest = null;

            foreach ($page->items as $product) {
                $catalog->ingest($connection, $product, $run, applyChanges: $this->mode === 'incremental');
                $newest = $this->later($newest, $product['updated_at'] ?? null);
            }

            $cursors->advance($connection, 'products', $page->endCursor, $newest);
            $after = $page->endCursor;

            if (! $page->hasNextPage || $after === null) {
                return null;
            }
        }

        return $after;
    }

    private function inventory(Connection $connection, SyncRun $run): ?string
    {
        $policy = SyncPolicy::for($connection);

        if ($policy->pushesStock()) {
            $queued = app(InventoryPush::class)->queueAll($connection);
            app(SyncRecorder::class)->log($connection, $run, 'info', "Queued {$queued} mapped item(s) for a full stock push to Shopify.", ['action' => 'queue_push']);
            app(SyncRecorder::class)->count($run, 'processed', $queued);
            app(SyncRecorder::class)->count($run, 'updated', $queued);

            if ($queued > 0 && app(Outbox::class)->hasDue($connection, InventoryPush::KIND)) {
                PushInventory::dispatch($connection->id);
            }

            return null;
        }

        $mappings = app(\Inventoros\Plugins\Shopify\Kit\Mapping\MappingStore::class)->mapped($connection, Catalog::ENTITY);
        $items = $mappings->map(fn ($m) => $m->meta('inventory_item_id'))->filter()->map(fn ($id) => (string) $id)->values()->all();

        if ($items === []) {
            return null;
        }

        $pull = app(InventoryPull::class);
        $recorder = app(SyncRecorder::class);

        foreach (app(ClientFactory::class)->make($connection)->inventoryLevels($items) as $level) {
            $recorder->count($run, 'processed');

            try {
                $outcome = $pull->apply($connection, $level['inventory_item_id'], $level['location_id'], $level['available'], $level['updated_at'], $run);
                $recorder->count($run, $outcome === 'applied' ? 'updated' : 'skipped');
            } catch (PermanentError $error) {
                $recorder->count($run, 'failed');
                $recorder->log($connection, $run, 'error', $error->getMessage(), [
                    'entity' => Catalog::ENTITY, 'external_id' => $level['inventory_item_id'], 'action' => 'pull_inventory',
                ]);
            }
        }

        return null;
    }

    private function orders(Connection $connection, SyncRun $run): ?string
    {
        $policy = SyncPolicy::for($connection);

        if (! $policy->importsOrders()) {
            return null;
        }

        $client = app(ClientFactory::class)->make($connection);
        $cursors = app(CursorStore::class);
        $importer = app(OrderImporter::class);
        $recorder = app(SyncRecorder::class);

        $updatedSince = $this->mode === 'incremental' ? $this->since($cursors->position($connection, 'orders')) : null;
        $createdSince = $this->mode === 'incremental' ? null : ($policy->ordersSince() ?? $connection->created_at?->toDateString());
        $after = $this->after;

        for ($pages = 0; $pages < self::MAX_PAGES; $pages++) {
            $page = $client->orders($after, $updatedSince, $createdSince === null ? null : Carbon::parse($createdSince)->startOfDay()->toIso8601String());
            $newest = null;

            foreach ($page->items as $order) {
                $recorder->count($run, 'processed');
                $newest = $this->later($newest, $order['updated_at'] ?? null);

                try {
                    $outcome = $importer->import($connection, $order, $run);
                    $recorder->count($run, match ($outcome) {
                        'created' => 'created',
                        'cancelled' => 'updated',
                        default => 'skipped',
                    });
                } catch (PermanentError $error) {
                    $recorder->count($run, 'failed');
                    $recorder->log($connection, $run, 'error', $error->getMessage(), [
                        'entity' => OrderImporter::ENTITY, 'external_id' => $order['id'], 'action' => 'import_order',
                    ]);
                    app(DeadLetterQueue::class)->record($connection, new ImportOrder($connection->id, (string) $order['id'], $order['name'] ?? null), $error->getMessage());
                }
            }

            $cursors->advance($connection, 'orders', $page->endCursor, $newest);
            $after = $page->endCursor;

            if (! $page->hasNextPage || $after === null) {
                return null;
            }
        }

        return $after;
    }

    private function since(?\Carbon\CarbonInterface $position): ?string
    {
        return $position?->copy()->subSeconds(self::OVERLAP_SECONDS)->utc()->toIso8601String();
    }

    private function later(?Carbon $current, mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return $current;
        }

        $time = Carbon::parse($value);

        return $current === null || $time->gt($current) ? $time : $current;
    }
}
