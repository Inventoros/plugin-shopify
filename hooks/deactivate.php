<?php

/**
 * Runs on deactivation, and before every update. Keeps every row: a
 * deactivated plugin may come back, and an update must find its data.
 */

use Illuminate\Support\Facades\Log;

Log::info('Shopify plugin deactivated; its data is kept until the plugin is deleted.');
