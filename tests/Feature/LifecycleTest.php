<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Plugin;
use App\Models\Role;
use App\Services\PluginService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Support\Migrations;
use Inventoros\Plugins\Shopify\Support\Permissions;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\TestCase;

final class LifecycleTest extends TestCase
{
    use ShopifyWorld;

    private const TABLES = [
        'plg_shopify_connections',
        'plg_shopify_sync_runs',
        'plg_shopify_sync_logs',
        'plg_shopify_webhook_inbox',
        'plg_shopify_mappings',
        'plg_shopify_cursors',
        'plg_shopify_outbox',
        'plg_shopify_dead_letters',
    ];

    public function test_activation_marks_the_plugin_active_and_creates_its_tables(): void
    {
        $this->assertTrue((bool) Plugin::where('slug', 'shopify')->value('is_active'));

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} should exist after activation");
        }
    }

    public function test_migrations_are_recorded_under_the_plugin_prefix(): void
    {
        $ran = DB::table('migrations')->where('migration', 'like', '%_plg_shopify_%')->pluck('migration');

        $this->assertCount(count(glob(Migrations::path().'/*.php')), $ran);
    }

    public function test_deactivating_keeps_the_data_and_reactivating_is_idempotent(): void
    {
        $this->setUpWorld();

        $plugins = app(PluginService::class);
        $plugins->deactivatePlugin('shopify');

        $this->assertFalse((bool) Plugin::where('slug', 'shopify')->value('is_active'));
        $this->assertTrue(Schema::hasTable('plg_shopify_connections'));

        $plugins->activatePlugin('shopify');

        $this->assertSame('Acme Store', Connection::query()->sole()->name);
        $this->assertSame(self::TOKEN, Connection::query()->sole()->credential('access_token'));
    }

    public function test_uninstall_drops_only_the_plugin_tables_and_keeps_imported_orders_and_stock(): void
    {
        $this->setUpWorld();
        $this->mapLocations();
        $this->mapCatalog();
        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk();

        $order = Order::withoutGlobalScopes()->where('source', 'shopify')->sole();
        $ledger = StockAdjustment::query()->count();

        $role = Role::where('slug', 'system-member')->firstOrFail();
        $role->update(['permissions' => ['view_products', Permissions::VIEW, Permissions::MANAGE]]);

        $coreMigrations = DB::table('migrations')->where('migration', 'not like', '%_plg_shopify_%')->count();

        $this->runUninstallHook();

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} should be dropped on uninstall");
        }

        $this->assertSame(0, DB::table('migrations')->where('migration', 'like', '%_plg_shopify_%')->count());
        $this->assertSame($coreMigrations, DB::table('migrations')->count());
        $this->assertSame(['view_products'], $role->fresh()->permissions);

        $this->assertNotNull(Order::withoutGlobalScopes()->find($order->id), 'imported orders are core records and stay');
        $this->assertSame('shopify:acme-store.myshopify.com:5001', $order->fresh()->external_reference);
        $this->assertSame(8, $this->bolt->fresh()->stock);
        $this->assertSame($ledger, StockAdjustment::query()->count());
        $this->assertTrue(Schema::hasTable('orders'));
    }

    public function test_uninstall_is_safe_to_run_twice(): void
    {
        $this->runUninstallHook();
        $this->runUninstallHook();

        $this->assertFalse(Schema::hasTable('plg_shopify_connections'));
    }

    public function test_reinstalling_after_uninstall_starts_clean(): void
    {
        $this->runUninstallHook();

        Migrations::run();

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertSame(0, Connection::query()->count());
    }

    public function test_the_migrations_path_points_at_the_plugin(): void
    {
        $this->assertDirectoryExists(Migrations::path());
        $this->assertNotEmpty(glob(Migrations::path().'/*_plg_shopify_*.php'));
    }

    /**
     * Run hooks/uninstall.php the way PluginService::deletePlugin() does,
     * without deleting the plugin folder the test suite is running from.
     */
    private function runUninstallHook(): void
    {
        (static function (string $file): void {
            require $file;
        })(dirname(__DIR__, 2).'/hooks/uninstall.php');
    }
}
