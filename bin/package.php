<?php

/**
 * Builds the installable plugin ZIP: build/{slug}-{version}.zip (+ .sha256).
 *
 * The archive has exactly one top-level folder named after the slug, which is
 * what Inventoros' installer and the inventoros.com marketplace require. Only
 * runtime files are packed: no tests, CI files, ui/ sources or node_modules.
 * Entries use forward slashes and no symlinks are followed, on every OS.
 *
 * Usage: php bin/package.php        (build the UI first: cd ui && npm ci && npm run build)
 * Prints the ZIP path on success.
 */

declare(strict_types=1);

// The folder name inside the archive. bin/rename.php rewrites it.
const SLUG = 'shopify';

const PACKED = ['plugin.json', 'Plugin.php', 'bootstrap.php', 'assets', 'src', 'database', 'hooks', 'lang', 'dist', 'README.md', 'CHANGELOG.md', 'LICENSE'];

$root = dirname(__DIR__);
$manifest = json_decode((string) file_get_contents($root.'/plugin.json'), true);

if (! is_array($manifest) || ! is_string($manifest['version'] ?? null)) {
    fwrite(STDERR, "plugin.json is missing or has no version\n");
    exit(1);
}

$entry = $manifest['ui']['entry'] ?? null;
if (is_string($entry) && ! is_file($root.'/dist/'.$entry)) {
    fwrite(STDERR, "dist/{$entry} is missing: run (cd ui && npm ci && npm run build) first\n");
    exit(1);
}

$version = $manifest['version'];
@mkdir($root.'/build', 0775, true);
$zipPath = $root.'/build/'.SLUG.'-'.$version.'.zip';
@unlink($zipPath);

$zip = new ZipArchive;
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    fwrite(STDERR, "Could not create {$zipPath}\n");
    exit(1);
}

$files = [];
foreach (PACKED as $item) {
    $path = $root.'/'.$item;

    if (is_file($path)) {
        $files[] = $item;
    } elseif (is_dir($path)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                continue;
            }
            $files[] = $item.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($path) + 1));
        }
    }
}

sort($files);
foreach ($files as $relative) {
    $zip->addFile($root.'/'.$relative, SLUG.'/'.$relative);
}
$zip->close();

file_put_contents($zipPath.'.sha256', hash_file('sha256', $zipPath)."\n");

fwrite(STDOUT, $zipPath.PHP_EOL);
