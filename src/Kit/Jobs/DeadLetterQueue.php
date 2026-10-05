<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Jobs;

use Illuminate\Database\Eloquent\Builder;
use Inventoros\Plugins\Shopify\Kit\Kit;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use InvalidArgumentException;

/**
 * Work that will not succeed by waiting: kept with the job that failed so
 * a person can fix the cause and retry it from the UI, or dismiss it.
 */
final class DeadLetterQueue
{
    public function record(Connection $connection, IntegrationJob $job, string $error): DeadLetter
    {
        return DeadLetter::create([
            'organization_id' => $connection->organization_id,
            'connection_id' => $connection->id,
            'kind' => $job->kind(),
            'reference' => $job->reference(),
            'payload' => ['job' => $job::class, 'args' => $job->arguments()],
            'error' => mb_substr($error, 0, 4000),
            'attempts' => $job->attempt,
            'failed_at' => now(),
        ]);
    }

    /**
     * @return Builder<DeadLetter>
     */
    public function open(Connection $connection): Builder
    {
        return DeadLetter::query()
            ->where('connection_id', $connection->id)
            ->whereNull('resolved_at');
    }

    /**
     * Queue the failed work again, from its first attempt. The letter is
     * resolved; if the work fails again it is dead-lettered anew.
     */
    public function retry(DeadLetter $letter, ?int $userId = null): IntegrationJob
    {
        $job = $this->rebuild($letter);

        $letter->forceFill([
            'retried_at' => now(),
            'retried_by' => $userId,
            'resolved_at' => now(),
        ])->save();

        dispatch($job);

        return $job;
    }

    public function dismiss(DeadLetter $letter, ?int $userId = null): void
    {
        $letter->forceFill(['resolved_at' => now(), 'retried_by' => $userId])->save();
    }

    /**
     * Rebuild the job from the stored class and arguments. Only job classes
     * of this plugin are accepted, so a tampered row cannot run anything
     * else.
     */
    public function rebuild(DeadLetter $letter): IntegrationJob
    {
        $class = $letter->payload['job'] ?? null;
        $args = $letter->payload['args'] ?? null;
        $namespace = substr(Kit::class, 0, (int) strrpos(Kit::class, '\\Kit\\')).'\\';

        if (! is_string($class) || ! str_starts_with($class, $namespace) || ! is_subclass_of($class, IntegrationJob::class) || ! is_array($args)) {
            throw new InvalidArgumentException('This failed item cannot be retried.');
        }

        $job = new $class(...$args);

        if ($job->connectionId() !== $letter->connection_id) {
            throw new InvalidArgumentException('This failed item belongs to another connection.');
        }

        return $job;
    }
}
