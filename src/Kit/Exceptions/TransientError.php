<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Exceptions;

/**
 * A failure that may go away on its own: a 5xx, a timeout, a dropped
 * connection. Retried with backoff, then dead-lettered.
 */
class TransientError extends IntegrationException
{
    public function retryable(): bool
    {
        return true;
    }
}
