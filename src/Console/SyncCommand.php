<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Console;

use Illuminate\Console\Command;
use Inventoros\Plugins\Shopify\Jobs\ProcessWebhook;
use Inventoros\Plugins\Shopify\Jobs\PushInventory;
use Inventoros\Plugins\Shopify\Jobs\PushProducts;
use Inventoros\Plugins\Shopify\Jobs\RunSync;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\WebhookInboxEntry;
use Inventoros\Plugins\Shopify\Kit\Outbox\Outbox;
use Inventoros\Plugins\Shopify\Sync\InventoryPush;
use Inventoros\Plugins\Shopify\Sync\ProductPush;

/**
 * shopify:sync, every 15 minutes: for each connection that can sync,
 * queue an incremental sync (products and orders changed since the
 * cursor), re-queue inbox entries left unprocessed (a worker died, the
 * connection was paused) and drain due outbox rows. Only queues work.
 */
final class SyncCommand extends Command
{
    protected $signature = 'shopify:sync {--connection= : Only this connection id}';

    protected $description = 'Queue incremental Shopify syncs and pick up waiting webhook and push work';

    public function handle(Outbox $outbox): int
    {
        $connections = Connection::query()
            ->where('status', Connection::CONNECTED)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey((int) $id))
            ->get();

        foreach ($connections as $connection) {
            if (! $connection->canSync()) {
                $this->line("{$connection->shop_domain}: paused, skipped.");

                continue;
            }

            RunSync::dispatch($connection->id, ['products', 'orders'], 'incremental', 'schedule');

            $waiting = WebhookInboxEntry::query()
                ->where('connection_id', $connection->id)
                ->whereIn('status', [WebhookInboxEntry::RECEIVED, WebhookInboxEntry::FAILED])
                ->where('updated_at', '<', now()->subMinutes(2))
                ->orderBy('id')
                ->limit(500)
                ->pluck('id');

            foreach ($waiting as $entryId) {
                ProcessWebhook::dispatch($connection->id, (int) $entryId);
            }

            if ($outbox->hasDue($connection, InventoryPush::KIND)) {
                PushInventory::dispatch($connection->id);
            }

            if ($outbox->hasDue($connection, ProductPush::KIND)) {
                PushProducts::dispatch($connection->id);
            }

            $this->line("{$connection->shop_domain}: incremental sync queued, {$waiting->count()} webhook(s) re-queued.");
        }

        return self::SUCCESS;
    }
}
