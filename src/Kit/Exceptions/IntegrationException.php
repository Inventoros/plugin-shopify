<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Exceptions;

use RuntimeException;

/**
 * Base of every error a provider adapter raises. Messages must be safe to
 * show and store: never include credentials or full request bodies.
 */
abstract class IntegrationException extends RuntimeException
{
    /** Whether retrying the same work later can succeed. */
    abstract public function retryable(): bool;
}
