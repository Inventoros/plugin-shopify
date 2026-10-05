<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Exceptions;

/**
 * The provider asked us to slow down (HTTP 429 with Retry-After, a GraphQL
 * THROTTLED error, or the local cost budget is empty). The work is retried
 * after $retryAfter seconds without counting as a failure.
 */
final class RateLimited extends TransientError
{
    public function __construct(string $message, public readonly int $retryAfter)
    {
        parent::__construct($message);
    }
}
