<?php

/**
 * Turns this template into a new plugin, in place.
 *
 *     php bin/rename.php --slug=cycle-counts --name="Basic Cycle Counts" --class=CycleCounts
 *
 * --slug   folder name and marketplace slug: lowercase letters, digits, hyphens
 * --name   display name
 * --class  StudlyCase PHP name: namespace Inventoros\Plugins\{class}, entry class {class}Plugin
 *
 * Rewrites the namespace, the table prefix (plg_{slug with underscores}_), the
 * permission, route, command and translation prefixes, file names that carry
 * the prefix, and the display name. Run it once on a fresh copy, then review
 * the diff.
 */

declare(strict_types=1);

$options = getopt('', ['slug:', 'name:', 'class:']);
$slug = $options['slug'] ?? '';
$name = $options['name'] ?? '';
$class = $options['class'] ?? '';

if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug) !== 1 || $name === '' || preg_match('/^[A-Z][A-Za-z0-9]*$/', $class) !== 1) {
    fwrite(STDERR, "Usage: php bin/rename.php --slug=my-plugin --name=\"My Plugin\" --class=MyPlugin\n");
    exit(2);
}

$root = dirname(__DIR__);
$snake = str_replace('-', '_', $slug);

// Order matters: specific tokens first, the bare words last.
$replacements = [
    'Inventoros\\\\Plugins\\\\Starter' => 'Inventoros\\\\Plugins\\\\'.$class,   // JSON-escaped
    'Inventoros\\Plugins\\Starter' => 'Inventoros\\Plugins\\'.$class,
    'StarterPlugin' => $class.'Plugin',
    'plg_starter_' => 'plg_'.$snake.'_',
    'inventoros-plugin-starter-ui' => 'inventoros-plugin-'.$slug.'-ui',
    "const SLUG = 'starter';" => "const SLUG = '".$slug."';",
    'starter' => $slug,
    'Starter' => $name,
];

$skip = ['/.git/', '/node_modules/', '/vendor/', '/build/', '/dist/', '/docs/', 'LICENSE', 'bin/rename.php'];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$changed = 0;

foreach ($files as $file) {
    $path = str_replace('\\', '/', $file->getPathname());

    foreach ($skip as $part) {
        if (str_contains($path, $part)) {
            continue 2;
        }
    }

    $content = (string) file_get_contents($path);
    $updated = strtr($content, $replacements);

    if ($updated !== $content) {
        file_put_contents($path, $updated);
        $changed++;
    }
}

// File names that carry the old names.
$renamed = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($files as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (str_contains($path, '/.git/') || str_contains($path, '/node_modules/')) {
        continue;
    }

    $base = basename($path);
    $new = strtr($base, ['StarterPlugin' => $class.'Plugin', 'plg_starter_' => 'plg_'.$snake.'_']);
    if ($new !== $base) {
        rename($path, dirname($path).'/'.$new);
        $renamed++;
    }
}

fwrite(STDOUT, "Rewrote {$changed} files and renamed {$renamed}. Review the diff, then update README.md and CHANGELOG.md.\n");
