<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Jobs;

use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Shopify\SyncPolicy;
use Inventoros\Plugins\Shopify\Sync\FulfilmentPush;

class PushFulfilments extends PushInventory
{
    protected function kindOfWork(): string
    {
        return FulfilmentPush::KIND;
    }

    protected function allowed(Connection $connection): bool
    {
        return SyncPolicy::for($connection)->pushesFulfilments();
    }

    protected function pushBatch(Connection $connection, $entries, $run): void
    {
        app(FulfilmentPush::class)->push($connection, $entries, $run);
    }
}
