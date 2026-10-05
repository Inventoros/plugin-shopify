<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Sync;

use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\SyncLog;
use Inventoros\Plugins\Shopify\Kit\Models\SyncRun;
use Throwable;

/**
 * Writes sync history: runs, their counters and per-record log lines.
 *
 * Every message passes through scrub(), which removes the connection's
 * credential values and common token shapes, so a provider error that
 * echoes a header can never put a secret in the history.
 */
final class SyncRecorder
{
    public function start(Connection $connection, string $type, string $resource, string $trigger, ?int $userId = null): SyncRun
    {
        return SyncRun::create([
            'organization_id' => $connection->organization_id,
            'connection_id' => $connection->id,
            'user_id' => $userId,
            'type' => $type,
            'resource' => $resource,
            'trigger' => $trigger,
            'status' => SyncRun::RUNNING,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array{entity?: string|null, external_id?: string|int|null, internal_id?: int|null, action?: string|null, context?: array<string, mixed>|null}  $attributes
     */
    public function log(Connection $connection, ?SyncRun $run, string $level, string $message, array $attributes = []): SyncLog
    {
        $context = $attributes['context'] ?? null;

        if (is_array($context)) {
            $encoded = self::scrub($connection, (string) json_encode($context));
            $context = json_decode($encoded, true);
        }

        return SyncLog::create([
            'organization_id' => $connection->organization_id,
            'connection_id' => $connection->id,
            'sync_run_id' => $run?->id,
            'level' => $level,
            'entity' => $attributes['entity'] ?? null,
            'external_id' => isset($attributes['external_id']) ? (string) $attributes['external_id'] : null,
            'internal_id' => $attributes['internal_id'] ?? null,
            'action' => $attributes['action'] ?? null,
            'message' => mb_substr(self::scrub($connection, $message), 0, 2000),
            'context' => $context,
        ]);
    }

    public function count(SyncRun $run, string $counter, int $by = 1): void
    {
        if (! in_array($counter, SyncRun::COUNTERS, true) || $by === 0) {
            return;
        }

        $run->increment($counter, $by);
    }

    /**
     * Close a run. With an error it failed; otherwise it failed when records
     * failed and none succeeded, is partial when some of each, and succeeded
     * when none failed.
     */
    public function finish(SyncRun $run, ?Throwable $error = null, ?string $summary = null): SyncRun
    {
        $run->refresh();

        if ($error !== null) {
            $connection = $run->connection;
            $status = SyncRun::FAILED;
            $summary = $connection === null ? $error->getMessage() : self::scrub($connection, $error->getMessage());
        } elseif ($run->failed > 0) {
            $status = ($run->created + $run->updated + $run->skipped) > 0 ? SyncRun::PARTIAL : SyncRun::FAILED;
        } else {
            $status = SyncRun::SUCCEEDED;
        }

        $run->forceFill([
            'status' => $status,
            'error_summary' => $summary === null ? null : mb_substr($summary, 0, 2000),
            'finished_at' => now(),
        ])->save();

        return $run;
    }

    public static function scrub(Connection $connection, string $text): string
    {
        foreach ($connection->secrets() as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }

        // Shopify access tokens and API secrets, and bearer tokens in echoed headers.
        $text = (string) preg_replace('/\bshp(at|ss|ca|pa|ua)_[A-Za-z0-9]+/', '[redacted]', $text);

        return (string) preg_replace('/(Bearer|X-Shopify-Access-Token:?)\s+[^\s"\']+/i', '$1 [redacted]', $text);
    }
}
