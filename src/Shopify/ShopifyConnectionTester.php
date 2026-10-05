<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Shopify;

use Inventoros\Plugins\Shopify\Kit\Connections\ConnectionTester;
use Inventoros\Plugins\Shopify\Kit\Connections\TestResult;
use Inventoros\Plugins\Shopify\Kit\Exceptions\IntegrationException;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;

/**
 * Reads the shop (name, currency, plan): the cheapest call that proves the
 * token is valid for this store. Also checks that the webhook secret is
 * set, since without it no webhook can be verified.
 */
final class ShopifyConnectionTester implements ConnectionTester
{
    public function __construct(private readonly ClientFactory $clients) {}

    public function test(Connection $connection): TestResult
    {
        try {
            $shop = $this->clients->make($connection)->shop();
        } catch (IntegrationException $error) {
            return TestResult::failed($error->getMessage());
        }

        if (! $connection->hasCredential('webhook_secret')) {
            return TestResult::failed('The access token works, but the API secret key is missing: webhooks cannot be verified without it.');
        }

        if ($shop['domain'] !== '' && strtolower($shop['domain']) !== strtolower($connection->shop_domain)) {
            return TestResult::failed("The token belongs to {$shop['domain']}, not {$connection->shop_domain}.");
        }

        return TestResult::passed("Connected to {$shop['name']}.", [
            'shop_id' => $shop['id'],
            'shop_name' => $shop['name'],
            'currency' => $shop['currency'],
            'plan' => $shop['plan'],
        ]);
    }
}
