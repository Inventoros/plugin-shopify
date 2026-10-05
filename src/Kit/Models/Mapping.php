<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Models;

/**
 * An external record and the core record it stands for.
 *
 * Unique both ways per connection and entity: one external id maps to at
 * most one core record and the reverse. Only `mapped` rows drive sync;
 * `suggested` rows wait for a person to confirm them (the review step).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $connection_id
 * @property string $entity e.g. variant, location, order
 * @property string $external_id
 * @property string|null $external_parent_id
 * @property string|null $external_label
 * @property string|null $sku
 * @property string|null $internal_type e.g. product, variant, warehouse, order
 * @property int|null $internal_id
 * @property string $status mapped|suggested|unmatched|conflict|ignored|pending
 * @property array<string, mixed>|null $meta
 * @property \Illuminate\Support\Carbon|null $external_updated_at
 * @property \Illuminate\Support\Carbon|null $synced_at
 */
class Mapping extends KitModel
{
    protected const TABLE = 'mappings';

    /** Confirmed: sync uses it. */
    public const MAPPED = 'mapped';

    /** Matched automatically (by SKU); waiting for review. */
    public const SUGGESTED = 'suggested';

    /** Nothing in core matches. */
    public const UNMATCHED = 'unmatched';

    /** Several core records match, or the match is taken by another external record. */
    public const CONFLICT = 'conflict';

    /** Deliberately not synced. */
    public const IGNORED = 'ignored';

    /** Known, not yet mapped (a location before a warehouse is chosen). */
    public const PENDING = 'pending';

    public const NEEDS_REVIEW = [self::SUGGESTED, self::UNMATCHED, self::CONFLICT, self::PENDING];

    public const STATUSES = [self::MAPPED, self::SUGGESTED, self::UNMATCHED, self::CONFLICT, self::IGNORED, self::PENDING];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'internal_id' => 'integer',
            'external_updated_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return ($this->meta ?? [])[$key] ?? $default;
    }

    public function isMapped(): bool
    {
        return $this->status === self::MAPPED && $this->internal_id !== null;
    }
}
