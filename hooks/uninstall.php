<?php

/**
 * Runs when the plugin is deleted, whether or not it was active.
 *
 * Removes everything the plugin created and nothing else:
 *  - its own tables (by rolling back exactly its own migrations),
 *  - its permission names from roles and permission sets.
 * Core records (products, stock, orders) are never touched.
 */

use App\Models\PermissionSet;
use App\Models\Role;
use Inventoros\Plugins\Starter\Support\Migrations;

require_once dirname(__DIR__).'/bootstrap.php';

Migrations::reset();

$prefix = 'starter.';
$strip = static function ($model) use ($prefix): void {
    $permissions = $model->permissions ?? [];
    $kept = array_values(array_filter($permissions, fn ($name) => ! str_starts_with((string) $name, $prefix)));

    if (count($kept) !== count($permissions)) {
        $model->permissions = $kept;
        $model->save();
    }
};

Role::query()->whereNotNull('permissions')->each($strip);
PermissionSet::query()->whereNotNull('permissions')->each($strip);
