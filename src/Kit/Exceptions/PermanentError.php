<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Exceptions;

/**
 * A failure that retrying cannot fix: rejected credentials (401/403), a
 * missing record (404), a validation error, or data that needs a person
 * (an unmapped SKU, not enough stock). Dead-lettered at once so it can be
 * retried from the UI after the cause is fixed.
 *
 * $authentication marks a credentials failure: the connection is put into
 * the error state and stops syncing until it is tested again.
 */
final class PermanentError extends IntegrationException
{
    public function __construct(string $message, public readonly bool $authentication = false)
    {
        parent::__construct($message);
    }

    public function retryable(): bool
    {
        return false;
    }
}
