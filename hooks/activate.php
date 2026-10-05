<?php

/**
 * Runs on every activation (and after every update, which re-activates the
 * plugin). Brings the plugin's tables up to date. Throwing here aborts the
 * activation and leaves the plugin inactive.
 */

use Inventoros\Plugins\Starter\Support\Migrations;

require_once dirname(__DIR__).'/bootstrap.php';

Migrations::run();
