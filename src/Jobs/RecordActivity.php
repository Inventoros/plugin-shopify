<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Starter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Inventoros\Plugins\Starter\Models\Activity;

/**
 * Writes one activity line, off the request that triggered it.
 *
 * The job carries plain values, never models, so it still runs if the
 * triggering record is deleted before the worker gets to it. If the plugin is
 * deactivated while the job waits, its class is not loaded and the job fails
 * rather than running half-registered code; that is the intended behaviour.
 */
final class RecordActivity implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly int $organizationId,
        public readonly string $type,
        public readonly string $message,
    ) {}

    public function handle(): void
    {
        Activity::query()->create([
            'organization_id' => $this->organizationId,
            'type' => mb_substr($this->type, 0, 40),
            'message' => mb_substr($this->message, 0, 255),
        ]);
    }
}
