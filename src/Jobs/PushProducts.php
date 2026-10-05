<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Jobs;

use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Shopify\SyncPolicy;
use Inventoros\Plugins\Shopify\Sync\ProductPush;

/**
 * Drains the product outbox (titles and prices) when Inventoros owns
 * products. Same coalescing and retries as PushInventory.
 */
class PushProducts extends PushInventory
{
    public function reference(): ?string
    {
        return 'product push';
    }

    protected function kindOfWork(): string
    {
        return ProductPush::KIND;
    }

    protected function allowed(Connection $connection): bool
    {
        return SyncPolicy::for($connection)->pushesProducts();
    }

    protected function pushBatch(Connection $connection, $entries, $run): void
    {
        app(ProductPush::class)->push($connection, $entries, $run);
    }
}
