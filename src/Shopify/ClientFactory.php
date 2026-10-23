<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Shopify;

use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Support\CostBudget;

/**
 * Builds the client for a connection. Resolved from the container, so the
 * tests swap in a factory that returns a fake client.
 */
class ClientFactory
{
    public function make(Connection $connection): ShopifyClient
    {
        $token = app(AccessTokens::class)->token($connection);

        return new HttpShopifyClient($connection->shop_domain, $token, CostBudget::for($connection->id));
    }
}
