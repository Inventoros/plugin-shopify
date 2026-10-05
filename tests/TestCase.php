<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Starter\Tests;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\PluginService;
use App\Support\PluginPageRoutes;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inventoros\Plugins\Starter\StarterPlugin;
use Inventoros\Plugins\Starter\Support\Migrations;
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

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->activatePlugin();

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
        app(PluginService::class)->activatePlugin(StarterPlugin::SLUG);

        PluginPageRoutes::register();
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();

        $kernel = $this->app->make(Kernel::class);
        foreach (StarterPlugin::commands() as $command) {
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
