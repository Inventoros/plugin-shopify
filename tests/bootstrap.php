<?php

/**
 * PHPUnit bootstrap: the plugin's tests run inside an Inventoros checkout.
 *
 * The plugin lives at {inventoros}/plugins/shopify, so the application's
 * autoloader (and its Tests\ namespace) is two levels up. The plugin's own
 * autoloader maps Inventoros\Plugins\Shopify\ and its Tests\ namespace.
 */

$core = dirname(__DIR__, 3);

if (! is_file($core.'/vendor/autoload.php') || ! is_file($core.'/artisan')) {
    fwrite(STDERR, "Run the plugin tests from an Inventoros checkout: copy the plugin to {inventoros}/plugins/shopify, run composer install there, then vendor/bin/phpunit -c plugins/shopify/phpunit.xml\n");
    exit(1);
}

require $core.'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap.php';
