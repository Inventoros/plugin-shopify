<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One stored setting of one organization.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $key
 * @property mixed $value
 */
final class Setting extends Model
{
    protected $table = 'plg_shopify_settings';

    protected $fillable = ['organization_id', 'key', 'value'];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'value' => 'json',
        ];
    }
}
