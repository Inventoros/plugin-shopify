<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Carbon;
use Inventoros\Plugins\Shopify\Kit\Jobs\IntegrationJob;
use Inventoros\Plugins\Shopify\Kit\Kit;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\OutboxEntry;
use Inventoros\Plugins\Shopify\Kit\Outbox\Outbox;
use Inventoros\Plugins\Shopify\Kit\Sync\SyncRecorder;
use Inventoros\Plugins\Shopify\Shopify\SyncPolicy;
use Inventoros\Plugins\Shopify\Sync\InventoryPush;
use Throwable;

/**
 * Drains the connection's inventory outbox: every item that changed since
 * the last push, at its current quantity, in as few API calls as possible.
 *
 * At most one is queued per connection at a time (unique until it starts),
 * so a burst of stock changes becomes one delayed push. It re-queues
 * itself while due rows remain, and for rows not due yet.
 */
class PushInventory extends IntegrationJob implements ShouldBeUniqueUntilProcessing
{
    public const BATCH = 200;

    public int $uniqueFor = 600;

    public function __construct(public int $connectionKey) {}

    public function connectionId(): int
    {
        return $this->connectionKey;
    }

    public function arguments(): array
    {
        return [$this->connectionKey];
    }

    public function uniqueId(): string
    {
        return Kit::key('push', $this->kindOfWork(), $this->connectionKey);
    }

    public function reference(): ?string
    {
        return 'inventory push';
    }

    protected function kindOfWork(): string
    {
        return InventoryPush::KIND;
    }

    protected function allowed(Connection $connection): bool
    {
        return SyncPolicy::for($connection)->pushesStock();
    }

    protected function pushBatch(Connection $connection, $entries, $run): void
    {
        app(InventoryPush::class)->push($connection, $entries, $run);
    }

    protected function perform(Connection $connection): void
    {
        $outbox = app(Outbox::class);
        $kind = $this->kindOfWork();

        if (! $this->allowed($connection)) {
            // The policy changed after the rows were queued: drop them.
            OutboxEntry::query()->where('connection_id', $connection->id)->where('kind', $kind)->delete();

            return;
        }

        $entries = $outbox->due($connection, $kind, self::BATCH);

        if ($entries->isEmpty()) {
            $this->scheduleNext($connection);

            return;
        }

        $recorder = app(SyncRecorder::class);
        $run = $recorder->start($connection, 'push', $kind, 'hook');

        try {
            $this->pushBatch($connection, $entries, $run);
        } catch (Throwable $error) {
            $recorder->finish($run, $error);

            throw $error;
        }

        $recorder->finish($run);

        $this->scheduleNext($connection);
    }

    /**
     * Queue the next push when rows are waiting: now when due, else when the
     * earliest becomes due.
     */
    private function scheduleNext(Connection $connection): void
    {
        $next = OutboxEntry::query()
            ->where('connection_id', $connection->id)
            ->where('kind', $this->kindOfWork())
            ->orderBy('available_at')
            ->value('available_at');

        if ($next === null) {
            return;
        }

        $delay = max(0, (int) ceil(now()->diffInSeconds(Carbon::parse($next), false)));

        // The sync driver cannot wait: rows not due yet are left for the
        // scheduled sync command instead of looping here.
        if ($delay > 0 && config('queue.connections.'.($this->connection ?? config('queue.default')).'.driver', config('queue.default')) === 'sync') {
            return;
        }

        dispatch((new static($this->connectionKey))->delay(now()->addSeconds($delay)));
    }
}
