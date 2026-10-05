<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Jobs;

/**
 * The outcome of RetryPolicy::decide().
 */
final class RetryDecision
{
    public const RETRY = 'retry';

    public const THROTTLE = 'throttle';

    public const DEAD_LETTER = 'dead_letter';

    private function __construct(
        public readonly string $action,
        public readonly int $delay,
    ) {}

    public static function retry(int $delay): self
    {
        return new self(self::RETRY, $delay);
    }

    public static function throttle(int $delay): self
    {
        return new self(self::THROTTLE, $delay);
    }

    public static function deadLetter(): self
    {
        return new self(self::DEAD_LETTER, 0);
    }
}
