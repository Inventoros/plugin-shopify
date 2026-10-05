<?php

/**
 * Installs a built plugin ZIP into an Inventoros checkout through the real
 * installer, then activates, deactivates and deletes it, failing loudly at
 * the first problem. CI runs it against a freshly migrated database to prove
 * the release archive (not just the source tree) installs and uninstalls
 * cleanly.
 *
 * Usage: php bin/verify-package.php {inventoros-path} {zip} {slug}
 */

declare(strict_types=1);

use App\Models\Plugin;
use App\Services\PluginService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

[$script, $core, $zip, $slug] = array_pad($argv, 4, null);

if ($core === null || $zip === null || $slug === null) {
    fwrite(STDERR, "Usage: php bin/verify-package.php {inventoros-path} {zip} {slug}\n");
    exit(2);
}

$core = realpath($core) ?: $core;
$zip = realpath($zip) ?: $zip;

require $core.'/vendor/autoload.php';
$app = require $core.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();


$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$step = static fn (string $message) => fwrite(STDOUT, "ok   {$message}\n");

$plugins = app(PluginService::class);

if (is_dir(base_path("plugins/{$slug}"))) {
    $fail("plugins/{$slug} already exists in {$core}; remove it first so the ZIP is what gets installed");
}

$installed = $plugins->installFromZip($zip, $slug);
$step("installed {$installed['slug']} {$installed['manifest']['version']} from the ZIP");

$plugins->activatePlugin($slug);
Plugin::where('slug', $slug)->value('is_active') || $fail('the plugin is not active after activation');
$step('activated');

$ran = DB::table('migrations')->where('migration', 'like', '%_plg_'.str_replace('-', '_', $slug).'_%')->count();
$step("{$ran} plugin migration(s) recorded");

$plugins->deactivatePlugin($slug);
$step('deactivated');

$plugins->activatePlugin($slug);
$step('re-activated (idempotent)');

$plugins->deletePlugin($slug);
is_dir(base_path("plugins/{$slug}")) && $fail('the plugin folder survived deletion');
Plugin::where('slug', $slug)->exists() && $fail('the plugin record survived deletion');

$left = DB::table('migrations')->where('migration', 'like', '%_plg_'.str_replace('-', '_', $slug).'_%')->count();
$left === 0 || $fail("{$left} plugin migration(s) are still recorded after deletion");

$prefix = 'plg_'.str_replace('-', '_', $slug).'_';
$tables = array_filter(
    array_map(fn ($t) => is_array($t) ? ($t['name'] ?? '') : (string) $t, Schema::getTableListing()),
    fn ($name) => str_contains($name, $prefix),
);
$tables === [] || $fail('tables left behind after deletion: '.implode(', ', $tables));
$step('deleted: folder, record, migrations and tables are gone');

fwrite(STDOUT, "Package verified.\n");
