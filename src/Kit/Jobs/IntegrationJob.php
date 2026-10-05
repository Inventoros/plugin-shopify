<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Inventoros\Plugins\Shopify\Kit\Connections\ConnectionHealth;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Kit\Exceptions\RateLimited;
use Inventoros\Plugins\Shopify\Kit\Kit;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use Inventoros\Plugins\Shopify\Kit\Sync\SyncRecorder;
use Inventoros\Plugins\Shopify\Kit\Models\SyncLog;
use Throwable;

/**
 * Base of every job that talks to the provider.
 *
 * Retries are explicit re-dispatches rather than the queue's own attempts,
 * so they behave the same on every queue driver and the job controls the
 * waits:
 *
 *  - rate limited: re-queued after the provider's Retry-After (or the
 *    cost budget's estimate), without using up an attempt;
 *  - transient failure: re-queued after RetryPolicy's backoff, then
 *    dead-lettered after the last attempt;
 *  - permanent failure: dead-lettered at once (credentials failures also
 *    put the connection in the error state).
 *
 * Every failure is counted by ConnectionHealth (the circuit breaker); a
 * success closes it. A job for a connection that cannot sync (paused,
 * error, disconnected) does nothing; its work stays queued in the inbox or
 * outbox for the scheduled sync to pick up.
 *
 * Subclasses implement perform(), connectionId() and arguments() (the
 * constructor arguments that rebuild the job for a retry from the
 * dead-letter list). perform() must be idempotent: a retry repeats it.
 */
abstract class IntegrationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** The queue never retries on its own; see handle(). */
    public int $tries = 1;

    public int $timeout = 300;

    /** 1 for the first run; increases with each retry after a failure. */
    public int $attempt = 1;

    /** Rate-limit waits so far (they do not use up attempts). */
    public int $throttles = 0;

    abstract public function connectionId(): int;

    /**
     * Constructor arguments that rebuild this job (first attempt).
     *
     * @return array<int|string, mixed>
     */
    abstract public function arguments(): array;

    abstract protected function perform(Connection $connection): void;

    /** A short name for the dead-letter list, e.g. push_inventory. */
    public function kind(): string
    {
        return Str::snake(class_basename(static::class));
    }

    /** What the job was about (an inbox entry, a resource), for the dead-letter list. */
    public function reference(): ?string
    {
        return null;
    }

    protected function policy(): RetryPolicy
    {
        return new RetryPolicy;
    }

    /** False for jobs that must run whatever the connection's state (a connection test). */
    protected function requiresSyncableConnection(): bool
    {
        return true;
    }

    /** Called instead of perform() when the connection is in error or disconnected. */
    protected function skipped(Connection $connection): void {}

    /** Called after the job was dead-lettered. */
    protected function deadLettered(Connection $connection, DeadLetter $letter, Throwable $error): void {}

    public function handle(): void
    {
        $connection = Connection::query()->find($this->connectionId());

        if ($connection === null) {
            return;
        }

        // A paused (circuit open), failed or disconnected connection is left
        // alone. Nothing is lost: outbox rows and inbox entries stay where
        // they are, and the scheduled sync command picks them up again once
        // the connection can sync.
        if ($this->requiresSyncableConnection() && ! $connection->canSync()) {
            $this->skipped($connection);

            return;
        }

        try {
            $this->perform($connection);
        } catch (Throwable $error) {
            $this->handleFailure($connection, $error);

            return;
        }

        app(ConnectionHealth::class)->recordSuccess($connection);
    }

    /**
     * The queue gave up on the job outside handle() (a worker timeout):
     * dead-letter it like any other exhausted job.
     */
    public function failed(?Throwable $error = null): void
    {
        $connection = Connection::query()->find($this->connectionId());

        if ($connection !== null) {
            $this->attempt = max($this->attempt, $this->policy()->maxAttempts);
            $this->handleFailure($connection, $error ?? new \RuntimeException('The queue worker stopped this job.'));
        }
    }

    protected function handleFailure(Connection $connection, Throwable $error): void
    {
        $decision = $this->policy()->decide($error, $this->attempt, $this->throttles);
        $message = SyncRecorder::scrub($connection, $error->getMessage() !== '' ? $error->getMessage() : $error::class);

        if (! $error instanceof RateLimited) {
            app(ConnectionHealth::class)->recordFailure(
                $connection->refresh(),
                $message,
                $error instanceof PermanentError && $error->authentication,
            );
        }

        if ($decision->action !== RetryDecision::DEAD_LETTER) {
            if ($decision->action === RetryDecision::RETRY) {
                app(SyncRecorder::class)->log($connection, null, SyncLog::WARNING, "{$this->kind()} failed (attempt {$this->attempt}), retrying in {$decision->delay}s: {$message}", [
                    'action' => 'retry',
                    'context' => ['attempt' => $this->attempt, 'delay' => $decision->delay, 'reference' => $this->reference()],
                ]);
            }

            $this->redispatch($decision->delay, throttle: $decision->action === RetryDecision::THROTTLE);

            return;
        }

        $letter = app(DeadLetterQueue::class)->record($connection, $this, $message);

        app(SyncRecorder::class)->log($connection, null, SyncLog::ERROR, "{$this->kind()} gave up after {$this->attempt} attempt(s): {$message}", [
            'action' => 'dead_letter',
            'context' => ['dead_letter_id' => $letter->id, 'reference' => $this->reference()],
        ]);

        $this->deadLettered($connection, $letter, $error);

        Kit::fire('sync_failed', $connection, $letter);
    }

    /**
     * Queue a copy of this job to run after $delay seconds.
     */
    protected function redispatch(int $delay, bool $throttle): void
    {
        $next = clone $this;
        $next->job = null;

        if ($throttle) {
            $next->throttles = $this->throttles + 1;
        } else {
            $next->attempt = $this->attempt + 1;
        }

        dispatch($next->delay(now()->addSeconds($delay)));
    }
}
