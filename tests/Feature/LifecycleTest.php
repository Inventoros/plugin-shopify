<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Starter\Tests\Feature;

use App\Models\Inventory\Product;
use App\Models\Plugin;
use App\Models\Role;
use App\Services\PluginService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inventoros\Plugins\Starter\Support\Migrations;
use Inventoros\Plugins\Starter\Support\Permissions;
use Inventoros\Plugins\Starter\Support\Settings;
use Inventoros\Plugins\Starter\Tests\TestCase;

final class LifecycleTest extends TestCase
{
    private const TABLES = ['plg_starter_settings', 'plg_starter_activity'];

    public function test_activation_marks_the_plugin_active_and_creates_its_tables(): void
    {
        $this->assertTrue((bool) Plugin::where('slug', 'starter')->value('is_active'));

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} should exist after activation");
        }
    }

    public function test_migrations_are_recorded_under_the_plugin_prefix(): void
    {
        $ran = DB::table('migrations')->where('migration', 'like', '%_plg_starter_%')->pluck('migration');

        $this->assertCount(2, $ran);
    }

    public function test_deactivating_keeps_the_data_and_reactivating_is_idempotent(): void
    {
        Settings::for($this->organization->id)->put(['greeting' => 'Kept']);

        $plugins = app(PluginService::class);
        $plugins->deactivatePlugin('starter');

        $this->assertFalse((bool) Plugin::where('slug', 'starter')->value('is_active'));
        $this->assertTrue(Schema::hasTable('plg_starter_settings'));

        $plugins->activatePlugin('starter');

        $this->assertSame('Kept', Settings::for($this->organization->id)->get('greeting'));
        $this->assertSame(2, DB::table('migrations')->where('migration', 'like', '%_plg_starter_%')->count());
    }

    public function test_uninstall_removes_only_what_the_plugin_created(): void
    {
        $product = Product::factory()->create(['organization_id' => $this->organization->id]);
        Settings::for($this->organization->id)->put(['greeting' => 'Bye']);

        $role = Role::where('slug', 'system-member')->firstOrFail();
        $role->update(['permissions' => ['view_products', Permissions::MANAGE]]);

        $coreMigrations = DB::table('migrations')->where('migration', 'not like', '%_plg_starter_%')->count();

        $this->runUninstallHook();

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} should be dropped on uninstall");
        }

        $this->assertSame(0, DB::table('migrations')->where('migration', 'like', '%_plg_starter_%')->count());
        $this->assertSame($coreMigrations, DB::table('migrations')->count());
        $this->assertSame(['view_products'], $role->fresh()->permissions);
        $this->assertNotNull(Product::find($product->id), 'core data must survive uninstall');
        $this->assertTrue(Schema::hasTable('products'));
    }

    public function test_uninstall_is_safe_to_run_twice(): void
    {
        $this->runUninstallHook();
        $this->runUninstallHook();

        $this->assertFalse(Schema::hasTable('plg_starter_settings'));
    }

    public function test_the_migrations_path_points_at_the_plugin(): void
    {
        $this->assertDirectoryExists(Migrations::path());
        $this->assertCount(2, glob(Migrations::path().'/*_plg_starter_*.php'));
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
