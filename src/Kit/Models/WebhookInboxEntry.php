<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Models;

/**
 * One verified webhook delivery, stored before it is processed.
 *
 * (connection_id, event_id) and (connection_id, topic, payload_hash) are
 * unique, so a redelivery or a replay of the same body is acknowledged
 * without being stored or processed again.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $connection_id
 * @property string $topic
 * @property string $event_id
 * @property string $payload_hash
 * @property string|null $resource_id
 * @property string $payload
 * @property \Illuminate\Support\Carbon|null $triggered_at
 * @property string $status received|processing|processed|ignored|failed|dead
 * @property int $attempts
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $processed_at
 */
class WebhookInboxEntry extends KitModel
{
    protected const TABLE = 'webhook_inbox';

    public const RECEIVED = 'received';

    public const PROCESSING = 'processing';

    public const PROCESSED = 'processed';

    public const IGNORED = 'ignored';

    public const FAILED = 'failed';

    public const DEAD = 'dead';

    protected function casts(): array
    {
        return [
            'triggered_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $decoded = json_decode($this->payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
