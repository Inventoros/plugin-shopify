<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Jobs;

use Inventoros\Plugins\Shopify\Kit\Exceptions\IntegrationException;
use Inventoros\Plugins\Shopify\Kit\Exceptions\RateLimited;
use Throwable;

/**
 * What to do after a job failed: retry it later, wait for the rate limit,
 * or dead-letter it.
 *
 *  - RateLimited: wait as long as the provider asked (capped), without
 *    using up an attempt; dead-lettered after $maxThrottles waits.
 *  - A permanent error: dead-lettered at once.
 *  - Anything else (5xx, timeouts, unexpected exceptions): retried with
 *    the $backoff delays until $maxAttempts, then dead-lettered.
 */
final class RetryPolicy
{
    /**
     * @param  array<int, int>  $backoff  Seconds before attempt 2, 3, ... (the last value repeats)
     */
    public function __construct(
        public readonly int $maxAttempts = 5,
        public readonly array $backoff = [15, 60, 240, 900],
        public readonly int $maxThrottles = 30,
        public readonly int $maxThrottleWait = 900,
    ) {}

    public function decide(Throwable $error, int $attempt, int $throttles): RetryDecision
    {
        if ($error instanceof RateLimited) {
            if ($throttles >= $this->maxThrottles) {
                return RetryDecision::deadLetter();
            }

            return RetryDecision::throttle(min(max(1, $error->retryAfter), $this->maxThrottleWait));
        }

        if ($error instanceof IntegrationException && ! $error->retryable()) {
            return RetryDecision::deadLetter();
        }

        if ($attempt >= $this->maxAttempts) {
            return RetryDecision::deadLetter();
        }

        return RetryDecision::retry($this->delayBefore($attempt + 1));
    }

    /**
     * The wait before attempt $attempt (2 is the first retry).
     */
    public function delayBefore(int $attempt): int
    {
        if ($this->backoff === []) {
            return 0;
        }

        $index = min(max(0, $attempt - 2), count($this->backoff) - 1);

        return $this->backoff[$index];
    }
}
