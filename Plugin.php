<?php

/**
 * Starter: the Inventoros Official plugin template.
 *
 * Inventoros requires this file once per PHP process and calls the closure it
 * returns once per application (route:cache and the test suite boot more than
 * one). Everything the plugin registers happens in StarterPlugin::register().
 */

use Inventoros\Plugins\Starter\StarterPlugin;

require_once __DIR__.'/bootstrap.php';

return static function (string $slug, array $manifest): void {
    (new StarterPlugin($slug, $manifest, __DIR__))->register();
};
