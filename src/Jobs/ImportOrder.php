<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Jobs;

use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Kit\Jobs\IntegrationJob;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Sync\SyncRecorder;
use Inventoros\Plugins\Shopify\Shopify\ClientFactory;
use Inventoros\Plugins\Shopify\Sync\OrderImporter;
use Throwable;

/**
 * Fetches one Shopify order and imports it (a manual resync of an order,
 * or the retry of an order that failed during a sync). Idempotent: an
 * order already imported is left alone, or cancelled if Shopify cancelled
 * it.
 */
class ImportOrder extends IntegrationJob
{
    public function __construct(public int $connectionKey, public string $orderId, public ?string $label = null) {}

    public function connectionId(): int
    {
        return $this->connectionKey;
    }

    public function arguments(): array
    {
        return [$this->connectionKey, $this->orderId, $this->label];
    }

    public function reference(): ?string
    {
        return 'order '.($this->label ?? $this->orderId);
    }

    protected function perform(Connection $connection): void
    {
        $recorder = app(SyncRecorder::class);
        $run = $recorder->start($connection, 'manual', 'orders', 'retry');

        try {
            $order = app(ClientFactory::class)->make($connection)->order($this->orderId);

            if ($order === null) {
                throw new PermanentError("Shopify order {$this->orderId} no longer exists.");
            }

            $recorder->count($run, 'processed');
            $outcome = app(OrderImporter::class)->import($connection, $order, $run);
            $recorder->count($run, $outcome === 'created' ? 'created' : ($outcome === 'cancelled' ? 'updated' : 'skipped'));
        } catch (Throwable $error) {
            $recorder->finish($run, $error);

            throw $error;
        }

        $recorder->finish($run);
    }
}
