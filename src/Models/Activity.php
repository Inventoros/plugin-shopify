<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Starter\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A line in the plugin's activity log.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $type
 * @property string $message
 * @property \Illuminate\Support\Carbon $created_at
 */
final class Activity extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'plg_starter_activity';

    protected $fillable = ['organization_id', 'type', 'message'];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
