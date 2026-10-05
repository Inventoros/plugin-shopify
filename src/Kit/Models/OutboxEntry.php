<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Models;

/**
 * Outbound work waiting to be pushed, one row per (kind, subject): changes
 * to the same subject before the push coalesce into this one row.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $connection_id
 * @property string $kind
 * @property string $subject
 * @property array<string, mixed>|null $payload
 * @property int $coalesced
 * @property \Illuminate\Support\Carbon|null $available_at
 */
class OutboxEntry extends KitModel
{
    protected const TABLE = 'outbox';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'coalesced' => 'integer',
            'available_at' => 'datetime',
        ];
    }
}
