<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Models;

/**
 * How far incremental sync has read a resource: a page token and/or the
 * last processed timestamp.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $connection_id
 * @property string $resource
 * @property string|null $cursor
 * @property \Illuminate\Support\Carbon|null $position_at
 */
class Cursor extends KitModel
{
    protected const TABLE = 'cursors';

    protected function casts(): array
    {
        return ['position_at' => 'datetime'];
    }
}
