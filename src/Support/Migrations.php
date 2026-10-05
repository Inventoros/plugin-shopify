<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Support;

use Illuminate\Database\Migrations\Migrator;

/**
 * Runs and removes the plugin's own migrations (database/migrations).
 *
 * hooks/activate.php calls run() on every activation, which also covers
 * updates: the marketplace updater deactivates, swaps the files and activates
 * again, so a new version's migrations run then. Only pending migrations run,
 * so activation stays idempotent. They are recorded in the core `migrations`
 * table under their own file names, which carry the plugin's table prefix.
 *
 * hooks/uninstall.php calls reset(), which rolls back exactly the migrations
 * in this plugin's folder and nothing else. Deactivation never touches data.
 */
final class Migrations
{
    public static function path(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }

    public static function run(): void
    {
        $migrator = self::migrator();

        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        $migrator->run([self::path()]);
    }

    public static function reset(): void
    {
        $migrator = self::migrator();

        if (! $migrator->repositoryExists()) {
            return;
        }

        $migrator->reset([self::path()]);
    }

    private static function migrator(): Migrator
    {
        return app('migrator');
    }
}
