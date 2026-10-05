<?php

/**
 * Seeds the README screenshots: run from the Inventoros root after
 * `php artisan db:seed --class=E2ETestSeeder`, with the plugin active and
 * QUEUE_CONNECTION=sync.
 *
 *     php plugins/shopify/e2e/seed.php
 *
 * Connects a store served by the test suite's fake Shopify client (the
 * recorded fixtures in tests/Fixtures; nothing reaches Shopify), maps its
 * locations, runs a full sync and imports its orders, so every page has
 * realistic content: suggestions, a conflict, imported orders, pushes and
 * one order waiting in the error list. Safe to run again.
 */

declare(strict_types=1);

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Contracts\Console\Kernel;
use Inventoros\Plugins\Shopify\Jobs\RunSync;
use Inventoros\Plugins\Shopify\Kit\Mapping\MappingStore;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Shopify\ClientFactory;
use Inventoros\Plugins\Shopify\Shopify\SyncPolicy;
use Inventoros\Plugins\Shopify\Sync\Locations;
use Inventoros\Plugins\Shopify\Tests\Fakes\FakeClientFactory;
use Inventoros\Plugins\Shopify\Tests\Fakes\FakeShopifyClient;

$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require $root.'/plugins/shopify/bootstrap.php';

config(['queue.default' => 'sync']);
App\Models\System\SystemSetting::set('installed', true, 'boolean');

$user = User::where('email', 'e2e-test@inventoros.test')->firstOrFail();
$organization = Organization::findOrFail($user->organization_id);
auth()->login($user);

$fake = new FakeShopifyClient;
app()->instance(ClientFactory::class, new FakeClientFactory($fake));

Connection::query()->where('organization_id', $organization->id)->delete();

$warehouse = static fn (string $code, string $name) => Warehouse::updateOrCreate(
    ['organization_id' => $organization->id, 'code' => $code],
    ['name' => $name, 'is_active' => true],
);
$main = $warehouse('SHOP-MAIN', 'Main warehouse');
$annex = $warehouse('SHOP-POP', 'Pop-up store');

$bin = static fn (string $code, Warehouse $in) => ProductLocation::updateOrCreate(
    ['organization_id' => $organization->id, 'code' => $code],
    ['name' => 'Bin '.$code, 'warehouse_id' => $in->id, 'is_active' => true],
);
$binA = $bin('SH-A-01', $main);
$bin('SH-P-01', $annex);

$bolt = Product::withTrashed()->updateOrCreate(
    ['organization_id' => $organization->id, 'sku' => 'BOLT-1'],
    ['name' => 'Hex bolt', 'price' => 4, 'purchase_price' => 2, 'currency' => 'USD', 'stock' => 40, 'min_stock' => 5, 'is_active' => true, 'location_id' => $binA->id, 'deleted_at' => null],
);
ProductLocationStock::updateOrCreate(
    ['product_id' => $bolt->id, 'location_id' => $binA->id],
    ['organization_id' => $organization->id, 'quantity' => 40],
);

$tee = Product::withTrashed()->updateOrCreate(
    ['organization_id' => $organization->id, 'sku' => 'TEE'],
    ['name' => 'T-shirt', 'price' => 15, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0, 'is_active' => true, 'has_variants' => true, 'deleted_at' => null],
);
foreach (['S' => 12, 'M' => 9] as $size => $stock) {
    ProductVariant::withTrashed()->updateOrCreate(
        ['organization_id' => $organization->id, 'sku' => 'TEE-'.$size],
        ['product_id' => $tee->id, 'title' => $size, 'option_values' => ['Size' => $size], 'price' => 15, 'stock' => $stock, 'min_stock' => 0, 'is_active' => true, 'deleted_at' => null],
    );
}

$connection = Connection::create([
    'organization_id' => $organization->id,
    'acting_user_id' => $user->id,
    'name' => 'Acme Store',
    'shop_domain' => 'acme-store.myshopify.com',
    'credentials' => ['access_token' => 'shpat_screenshot_only', 'webhook_secret' => 'screenshot_only'],
    'status' => Connection::CONNECTED,
    'remote' => ['shop_name' => 'Acme Store', 'currency' => 'USD', 'plan' => 'Basic', 'webhooks_registered_at' => now()->subHours(3)->toIso8601String()],
    'settings' => array_merge(SyncPolicy::sanitize(['orders_since' => '2026-01-01']), ['push_delay_seconds' => 0]),
    'last_tested_at' => now()->subHours(3),
    'last_success_at' => now(),
]);

RunSync::dispatch($connection->id, ['locations'], 'initial', 'user', $user->id);

$store = app(MappingStore::class);
$locations = app(Locations::class);
$locations->map($store->find($connection, 'location', '1001'), Locations::WAREHOUSE, $main->id, true);
$locations->map($store->find($connection, 'location', '1002'), Locations::WAREHOUSE, $annex->id, false);

RunSync::dispatch($connection->id, ['products'], 'initial', 'user', $user->id);

// Confirm the bolt and the small T-shirt; leave the medium as a suggestion to review.
foreach (['3001', '3002'] as $variant) {
    $store->mark($store->find($connection, 'variant', $variant), Mapping::MAPPED);
}

RunSync::dispatch($connection->id, ['inventory', 'orders'], 'initial', 'user', $user->id);

StockAdjustment::adjust($bolt->fresh(), 12, 'manual', 'Received from supplier', null, null, true, $user, $binA->id);

echo json_encode(['connection' => $connection->id]).PHP_EOL;
