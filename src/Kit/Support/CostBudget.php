<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Support;

use Illuminate\Support\Facades\Cache;
use Inventoros\Plugins\Shopify\Kit\Exceptions\RateLimited;
use Inventoros\Plugins\Shopify\Kit\Kit;

/**
 * A per-connection leaky bucket that mirrors the provider's own (Shopify's
 * GraphQL cost limit: a bucket of maximumAvailable points refilled at
 * restoreRate points per second).
 *
 * Before a request, reserve() takes the request's expected cost from the
 * bucket, or throws RateLimited with the seconds until it would fit, so the
 * job is re-queued instead of sleeping in a worker. After a response,
 * observe() replaces the estimate with the provider's reported state.
 *
 * Kept in the cache store (shared by every worker on a real install); a
 * cold cache starts from a full bucket, which is what the provider does.
 */
final class CostBudget
{
    public function __construct(
        private readonly string $bucket,
        private readonly float $maximum = 1000.0,
        private readonly float $restoreRate = 50.0,
    ) {}

    public static function for(int $connectionId, float $maximum = 1000.0, float $restoreRate = 50.0): self
    {
        return new self(Kit::key('cost', $connectionId), $maximum, $restoreRate);
    }

    /**
     * Take $cost points, or throw RateLimited with the wait until they exist.
     */
    public function reserve(float $cost): void
    {
        $state = $this->state();
        $available = $this->availableNow($state);

        if ($available < $cost) {
            $wait = (int) ceil(($cost - $available) / max(0.1, $state['restore']));

            throw new RateLimited('Request cost budget is empty; waiting for it to refill.', max(1, $wait));
        }

        $this->put($available - $cost, $state['max'], $state['restore']);
    }

    /**
     * Record what the provider reports after a request.
     */
    public function observe(float $currentlyAvailable, ?float $maximum = null, ?float $restoreRate = null): void
    {
        $state = $this->state();

        $this->put($currentlyAvailable, $maximum ?? $state['max'], $restoreRate ?? $state['restore']);
    }

    /**
     * Seconds until $cost points are available (0 when they are now).
     */
    public function waitFor(float $cost): int
    {
        $state = $this->state();
        $available = $this->availableNow($state);

        return $available >= $cost ? 0 : (int) ceil(($cost - $available) / max(0.1, $state['restore']));
    }

    public function available(): float
    {
        return $this->availableNow($this->state());
    }

    public function reset(): void
    {
        Cache::forget($this->bucket);
    }

    /**
     * @return array{available: float, max: float, restore: float, at: float}
     */
    private function state(): array
    {
        $state = Cache::get($this->bucket);

        if (! is_array($state) || ! isset($state['available'], $state['max'], $state['restore'], $state['at'])) {
            return ['available' => $this->maximum, 'max' => $this->maximum, 'restore' => $this->restoreRate, 'at' => microtime(true)];
        }

        return $state;
    }

    /**
     * @param  array{available: float, max: float, restore: float, at: float}  $state
     */
    private function availableNow(array $state): float
    {
        $elapsed = max(0.0, microtime(true) - (float) $state['at']);

        return min((float) $state['max'], (float) $state['available'] + $elapsed * (float) $state['restore']);
    }

    private function put(float $available, float $maximum, float $restore): void
    {
        Cache::put($this->bucket, [
            'available' => max(0.0, $available),
            'max' => $maximum,
            'restore' => $restore,
            'at' => microtime(true),
        ], now()->addHour());
    }
}
