<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\PluginService;
use App\Support\PluginPageRoutes;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inventoros\Plugins\Shopify\Shopify\ClientFactory;
use Inventoros\Plugins\Shopify\Support\Listeners;
use Inventoros\Plugins\Shopify\Tests\Fakes\FakeClientFactory;
use Inventoros\Plugins\Shopify\Tests\Fakes\FakeShopifyClient;
use Inventoros\Plugins\Shopify\ShopifyPlugin;
use Inventoros\Plugins\Shopify\Support\Migrations;
use Tests\TestCase as InventorosTestCase;

/**
 * Boots Inventoros with the plugin activated through the real lifecycle.
 *
 * At runtime an active plugin is loaded while the application boots, before
 * routes/web.php adds plugin pages. In a test the database (and so the
 * plugins table) only exists after boot, so the plugin is activated here and
 * its pages and console commands are added the way boot would have added
 * them.
 */
abstract class TestCase extends InventorosTestCase
{
    use RefreshDatabase {
        migrateDatabases as migrateCoreDatabases;
    }

    protected Organization $organization;

    protected User $admin;

    protected User $member;

    protected FakeShopifyClient $shopify;

    protected FakeClientFactory $clients;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->activatePlugin();

        // No test may reach Shopify: every store is a fake built from the
        // recorded responses in tests/Fixtures.
        Http::preventStrayRequests();
        $this->shopify = new FakeShopifyClient;
        $this->clients = new FakeClientFactory($this->shopify);
        $this->app->instance(ClientFactory::class, $this->clients);
        Listeners::flush();

        $this->organization = $this->makeOrganization('Acme');
        $this->admin = $this->makeUser($this->organization, 'admin');
        $this->member = $this->makeUser($this->organization, 'member');

        Role::firstOrCreate(['slug' => 'system-member'], [
            'name' => 'Member', 'is_system' => true, 'permissions' => ['view_products'],
        ]);
    }

    /**
     * Create the plugin's tables right after the core schema, before
     * RefreshDatabase opens its per-test transaction. MySQL commits DDL
     * implicitly, so creating them inside the transaction would end it.
     *
     * afterRefreshingDatabase() runs AFTER that transaction has begun, so on
     * MySQL it silently turned every test into autocommit and rollbacks to
     * savepoints failed. Migrating here keeps the DDL outside it.
     */
    protected function migrateDatabases()
    {
        $this->migrateCoreDatabases();

        Migrations::run();
    }

    protected function activatePlugin(): void
    {
        app(PluginService::class)->activatePlugin(ShopifyPlugin::SLUG);

        PluginPageRoutes::register();
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();

        $kernel = $this->app->make(Kernel::class);
        foreach (ShopifyPlugin::commands() as $command) {
            $kernel->registerCommand($this->app->make($command));
        }
    }

    protected function makeOrganization(string $name): Organization
    {
        // Unique per process: on MySQL a test that runs DDL (uninstall)
        // commits its rows, so names and emails must not repeat.
        $suffix = bin2hex(random_bytes(4));

        return Organization::create([
            'name' => $name,
            'email' => strtolower($name).'-'.$suffix.'@example.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
    }

    protected function makeUser(Organization $organization, string $role): User
    {
        $n = bin2hex(random_bytes(4));

        return User::create([
            'name' => ucfirst($role).' '.$n,
            'email' => "{$role}-{$n}@example.com",
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
            'role' => $role,
        ]);
    }

    /**
     * Give the member role a permission (by name).
     */
    protected function grantToMembers(string $permission): void
    {
        $role = Role::where('slug', 'system-member')->firstOrFail();
        $role->grantPermission($permission);
    }
}
