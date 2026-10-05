<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Models;

/**
 * One line of sync history: what happened to one record, or a warning or
 * error. Messages and context never hold credentials (SyncRecorder scrubs
 * them).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $connection_id
 * @property int|null $sync_run_id
 * @property string $level info|warning|error
 * @property string|null $entity
 * @property string|null $external_id
 * @property int|null $internal_id
 * @property string|null $action
 * @property string $message
 * @property array<string, mixed>|null $context
 */
class SyncLog extends KitModel
{
    protected const TABLE = 'sync_logs';

    public const INFO = 'info';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    protected function casts(): array
    {
        return ['context' => 'array'];
    }
}
