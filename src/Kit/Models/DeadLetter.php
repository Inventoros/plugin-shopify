<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Models;

/**
 * Work that failed permanently or ran out of retries, kept with what is
 * needed to run it again from the UI.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $connection_id
 * @property string $kind
 * @property string|null $reference
 * @property array<string, mixed>|null $payload {job: class-string, args: array}
 * @property string $error
 * @property int $attempts
 * @property \Illuminate\Support\Carbon $failed_at
 * @property \Illuminate\Support\Carbon|null $retried_at
 * @property int|null $retried_by
 * @property \Illuminate\Support\Carbon|null $resolved_at
 */
class DeadLetter extends KitModel
{
    protected const TABLE = 'dead_letters';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'failed_at' => 'datetime',
            'retried_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
