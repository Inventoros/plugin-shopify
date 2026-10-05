<?php

/**
 * Registers this plugin's PSR-4 autoloader.
 *
 * Inventoros has no composer step for plugins (cPanel installs never run
 * composer), so every plugin maps its own namespace onto its own src/
 * directory. This file is require_once'd by Plugin.php and by the lifecycle
 * files in hooks/, so the classes are available wherever the plugin's code
 * runs, including hooks/uninstall.php on an inactive plugin.
 *
 * It declares no named functions or classes at its top level, so requiring it
 * again is harmless.
 */

(static function (): void {
    $prefixes = [
        'Inventoros\\Plugins\\Shopify\\Tests\\' => __DIR__.'/tests/',
        'Inventoros\\Plugins\\Shopify\\' => __DIR__.'/src/',
    ];

    spl_autoload_register(static function (string $class) use ($prefixes): void {
        foreach ($prefixes as $prefix => $directory) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            $file = $directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            if (is_file($file)) {
                require $file;
            }

            return;
        }
    });
})();
