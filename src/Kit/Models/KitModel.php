<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Inventoros\Plugins\Shopify\Kit\Kit;

/**
 * Base of the kit's models: table names come from Kit::table(), and every
 * query a caller writes goes through forOrganization() (the kit has no
 * global scope, because its jobs run without a signed-in user).
 *
 * @method static Builder<static> forOrganization(int $organizationId)
 */
abstract class KitModel extends Model
{
    /** The table name without the plugin prefix. */
    protected const TABLE = '';

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return Kit::table(static::TABLE);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where($this->qualifyColumn('organization_id'), $organizationId);
    }
}
