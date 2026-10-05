<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Shopify;

use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
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
        $token = $connection->credential('access_token');

        if ($token === null) {
            throw new PermanentError('This connection has no access token. Add one in the connection settings.', authentication: true);
        }

        if (! ShopDomain::valid($connection->shop_domain)) {
            throw new PermanentError('The shop domain is not a myshopify.com address.');
        }

        return new HttpShopifyClient($connection->shop_domain, $token, CostBudget::for($connection->id));
    }
}
