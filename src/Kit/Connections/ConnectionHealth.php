<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Connections;

use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use Inventoros\Plugins\Shopify\Kit\Models\OutboxEntry;
use Inventoros\Plugins\Shopify\Kit\Models\SyncRun;
use Inventoros\Plugins\Shopify\Kit\Models\WebhookInboxEntry;

/**
 * Connection health and the circuit breaker.
 *
 * Every provider failure counts; after CIRCUIT_THRESHOLD in a row the
 * connection pauses for PAUSE_MINUTES (doubling up to MAX_PAUSE_MINUTES)
 * and says why, so a store that is down is not hammered and its errors do
 * not flood the log. A success closes the circuit. Rejected credentials
 * put the connection in the error state: it stops until a test passes.
 */
final class ConnectionHealth
{
    public const CIRCUIT_THRESHOLD = 5;

    public const PAUSE_MINUTES = 15;

    public const MAX_PAUSE_MINUTES = 240;

    public function recordSuccess(Connection $connection): void
    {
        $fresh = $connection->consecutive_failures === 0
            && $connection->paused_until === null
            && $connection->last_success_at !== null
            && $connection->last_success_at->gt(now()->subMinute());

        if ($fresh) {
            return;
        }

        $connection->forceFill([
            'last_success_at' => now(),
            'consecutive_failures' => 0,
            'paused_until' => null,
        ])->save();
    }

    public function recordFailure(Connection $connection, string $message, bool $authentication = false): void
    {
        $failures = $connection->consecutive_failures + 1;
        $attributes = [
            'last_failure_at' => now(),
            'last_error' => mb_substr($message, 0, 2000),
            'consecutive_failures' => $failures,
        ];

        if ($authentication) {
            $attributes['status'] = Connection::ERROR;
        } elseif ($failures >= self::CIRCUIT_THRESHOLD) {
            $trips = intdiv($failures, self::CIRCUIT_THRESHOLD) - 1;
            $minutes = min(self::MAX_PAUSE_MINUTES, self::PAUSE_MINUTES * (2 ** min($trips, 8)));
            $attributes['paused_until'] = now()->addMinutes($minutes);
        }

        $connection->forceFill($attributes)->save();
    }

    /**
     * Close the circuit by hand (the "resume now" button).
     */
    public function resume(Connection $connection): void
    {
        $connection->forceFill(['paused_until' => null, 'consecutive_failures' => 0])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Connection $connection): array
    {
        $since = now()->subDay();

        $runs = SyncRun::query()->where('connection_id', $connection->id)->where('created_at', '>=', $since);
        $total = (clone $runs)->count();
        $failed = (clone $runs)->whereIn('status', [SyncRun::FAILED, SyncRun::PARTIAL])->count();

        $lastRun = SyncRun::query()
            ->where('connection_id', $connection->id)
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->latest('id')
            ->first();

        $inbox = WebhookInboxEntry::query()
            ->where('connection_id', $connection->id)
            ->whereIn('status', [WebhookInboxEntry::RECEIVED, WebhookInboxEntry::PROCESSING, WebhookInboxEntry::FAILED])
            ->count();
        $outbox = OutboxEntry::query()->where('connection_id', $connection->id)->count();
        $running = SyncRun::query()
            ->where('connection_id', $connection->id)
            ->whereIn('status', [SyncRun::QUEUED, SyncRun::RUNNING])
            ->count();

        return [
            'state' => $this->state($connection),
            'last_sync_at' => $lastRun?->finished_at?->toIso8601String(),
            'last_sync_status' => $lastRun?->status,
            'last_success_at' => $connection->last_success_at?->toIso8601String(),
            'last_failure_at' => $connection->last_failure_at?->toIso8601String(),
            'last_error' => $connection->last_error,
            'paused_until' => $connection->isPaused() ? $connection->paused_until?->toIso8601String() : null,
            'consecutive_failures' => $connection->consecutive_failures,
            'queue_depth' => $inbox + $outbox + $running,
            'queue' => ['inbox' => $inbox, 'outbox' => $outbox, 'running' => $running],
            'runs_24h' => $total,
            'failed_runs_24h' => $failed,
            'error_rate' => $total === 0 ? 0.0 : round($failed / $total * 100, 1),
            'open_dead_letters' => DeadLetter::query()->where('connection_id', $connection->id)->whereNull('resolved_at')->count(),
        ];
    }

    /**
     * healthy, degraded (recent failures), paused (circuit open), error,
     * disconnected or pending.
     */
    public function state(Connection $connection): string
    {
        return match (true) {
            $connection->status === Connection::DISCONNECTED => 'disconnected',
            $connection->status === Connection::ERROR => 'error',
            $connection->status === Connection::PENDING => 'pending',
            $connection->isPaused() => 'paused',
            $connection->consecutive_failures > 0 => 'degraded',
            default => 'healthy',
        };
    }
}
