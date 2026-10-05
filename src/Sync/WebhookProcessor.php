<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Sync;

use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\SyncRun;
use Inventoros\Plugins\Shopify\Kit\Models\WebhookInboxEntry;
use Inventoros\Plugins\Shopify\Kit\Sync\SyncRecorder;
use Inventoros\Plugins\Shopify\Shopify\Normalizer;
use Inventoros\Plugins\Shopify\Shopify\SyncPolicy;

/**
 * Applies one stored webhook delivery. Called by ProcessWebhook after it
 * claimed the inbox entry, so each delivery is applied once.
 */
final class WebhookProcessor
{
    public const TOPICS = [
        'orders/create' => 'ORDERS_CREATE',
        'orders/updated' => 'ORDERS_UPDATED',
        'orders/cancelled' => 'ORDERS_CANCELLED',
        'products/create' => 'PRODUCTS_CREATE',
        'products/update' => 'PRODUCTS_UPDATE',
        'inventory_levels/update' => 'INVENTORY_LEVELS_UPDATE',
        'app/uninstalled' => 'APP_UNINSTALLED',
    ];

    public const UNINSTALLED = 'app/uninstalled';

    public function __construct(
        private readonly OrderImporter $orders,
        private readonly Catalog $catalog,
        private readonly InventoryPull $inventory,
        private readonly SyncRecorder $recorder,
    ) {}

    /**
     * @return string|null why the delivery was ignored, or null when it was applied
     */
    public function process(Connection $connection, WebhookInboxEntry $entry, SyncRun $run): ?string
    {
        $data = $entry->data();
        $policy = SyncPolicy::for($connection);

        switch ($entry->topic) {
            case 'orders/create':
            case 'orders/updated':
            case 'orders/cancelled':
                $outcome = $this->orders->import($connection, Normalizer::order($data), $run);
                $this->count($run, $outcome);

                return in_array($outcome, ['stale', 'skipped'], true) ? "order {$outcome}" : null;

            case 'products/create':
            case 'products/update':
                $this->catalog->ingest($connection, Normalizer::product($data), $run, applyChanges: true);

                return null;

            case 'inventory_levels/update':
                if (! $policy->pullsStock()) {
                    $this->count($run, 'skipped');

                    return 'Inventoros owns stock for this connection';
                }

                $outcome = $this->inventory->apply(
                    $connection,
                    (string) ($data['inventory_item_id'] ?? ''),
                    (string) ($data['location_id'] ?? ''),
                    (int) ($data['available'] ?? 0),
                    $data['updated_at'] ?? null,
                    $run,
                );
                $this->count($run, $outcome === 'applied' ? 'updated' : 'skipped');

                return $outcome === 'applied' || $outcome === 'unchanged' ? null : "inventory level {$outcome}";

            case self::UNINSTALLED:
                $this->uninstalled($connection, $run);

                return null;
        }

        $this->count($run, 'skipped');

        return "topic {$entry->topic} is not handled";
    }

    /**
     * The store removed the app: the token is dead. Stop syncing, forget the
     * credentials, keep the mappings and history (reconnecting reuses them).
     */
    private function uninstalled(Connection $connection, SyncRun $run): void
    {
        $connection->forceFill([
            'status' => Connection::DISCONNECTED,
            'credentials' => null,
            'last_error' => 'The app was uninstalled from the Shopify store.',
        ])->save();

        $this->recorder->log($connection, $run, 'warning', 'The app was uninstalled from the Shopify store. Syncing has stopped; the history and mappings are kept.', [
            'action' => 'uninstalled',
        ]);
    }

    private function count(SyncRun $run, string $outcome): void
    {
        $this->recorder->count($run, 'processed');
        $this->recorder->count($run, match ($outcome) {
            'created' => 'created',
            'cancelled', 'updated' => 'updated',
            default => 'skipped',
        });
    }
}
