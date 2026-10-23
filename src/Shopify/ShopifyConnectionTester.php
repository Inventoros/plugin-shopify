<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Shopify;

use Carbon\Carbon;
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

        $policy = SyncPolicy::for($connection);
        $required = ['read_products', 'read_inventory', 'read_locations'];
        if ($policy->pushesStock()) {
            $required[] = 'write_inventory';
        }
        if ($policy->pushesProducts()) {
            $required[] = 'write_products';
        }
        if ($policy->importsOrders()) {
            $required[] = 'read_orders';
            if ($policy->ordersSince() !== null && Carbon::parse($policy->ordersSince())->lt(now()->subDays(60))) {
                $required[] = 'read_all_orders';
            }
        }
        $granted = $shop['scopes'] ?? [];
        $missing = array_values(array_filter($required, fn ($scope) => ! in_array($scope, $granted, true)
            && ! (str_starts_with($scope, 'read_') && in_array('write_'.substr($scope, 5), $granted, true))));
        if ($missing !== []) {
            return TestResult::failed('Missing Shopify access scopes: '.implode(', ', $missing).'. Update the app permissions and reinstall or reauthorize it, then test again.');
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
