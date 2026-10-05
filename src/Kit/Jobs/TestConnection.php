<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Jobs;

use Inventoros\Plugins\Shopify\Kit\Connections\ConnectionTester;
use Inventoros\Plugins\Shopify\Kit\Kit;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\SyncLog;
use Inventoros\Plugins\Shopify\Kit\Sync\SyncRecorder;
use Throwable;

/**
 * Tests a connection's credentials in the background (the settings page
 * never waits on the provider) and moves it to connected or error.
 *
 * A passing test fires {HOOK_PREFIX}connection_verified so the plugin can
 * follow up (register webhooks, read locations).
 */
class TestConnection extends IntegrationJob
{
    public function __construct(public int $connectionKey, public ?int $userId = null) {}

    public function connectionId(): int
    {
        return $this->connectionKey;
    }

    public function arguments(): array
    {
        return [$this->connectionKey, $this->userId];
    }

    protected function requiresSyncableConnection(): bool
    {
        return false;
    }

    protected function perform(Connection $connection): void
    {
        if ($connection->status === Connection::DISCONNECTED) {
            return;
        }

        try {
            $result = app(ConnectionTester::class)->test($connection);
        } catch (Throwable $error) {
            $result = \Inventoros\Plugins\Shopify\Kit\Connections\TestResult::failed($error->getMessage());
        }

        $message = SyncRecorder::scrub($connection, $result->message);

        if (! $result->ok) {
            $connection->forceFill([
                'status' => Connection::ERROR,
                'last_tested_at' => now(),
                'last_failure_at' => now(),
                'last_error' => $message,
            ])->save();

            app(SyncRecorder::class)->log($connection, null, SyncLog::ERROR, 'Connection test failed: '.$message, ['action' => 'test']);

            return;
        }

        $connection->forceFill([
            'status' => Connection::CONNECTED,
            'remote' => $result->remote,
            'last_tested_at' => now(),
            'last_success_at' => now(),
            'last_error' => null,
            'consecutive_failures' => 0,
            'paused_until' => null,
        ])->save();

        app(SyncRecorder::class)->log($connection, null, SyncLog::INFO, 'Connection test passed: '.$message, ['action' => 'test']);

        Kit::fire('connection_verified', $connection, $this->userId);
    }
}
