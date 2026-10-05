<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One unit of sync work and its outcome: an initial or incremental sync of a
 * resource, a manual resync, a push, or one webhook delivery.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $connection_id
 * @property int|null $user_id
 * @property string $type initial|incremental|manual|push|webhook
 * @property string $resource
 * @property string $trigger schedule|user|webhook|hook|retry
 * @property string $status queued|running|succeeded|partial|failed
 * @property int $processed
 * @property int $created
 * @property int $updated
 * @property int $skipped
 * @property int $failed
 * @property string|null $error_summary
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 */
class SyncRun extends KitModel
{
    protected const TABLE = 'sync_runs';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    public const COUNTERS = ['processed', 'created', 'updated', 'skipped', 'failed'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'processed' => 'integer',
            'created' => 'integer',
            'updated' => 'integer',
            'skipped' => 'integer',
            'failed' => 'integer',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SyncLog::class, 'sync_run_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::PARTIAL, self::FAILED], true);
    }
}
