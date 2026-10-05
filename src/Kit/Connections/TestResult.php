<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Connections;

/**
 * The outcome of a connection test. $remote holds what the provider said
 * about the account (name, currency), stored on the connection for display.
 */
final class TestResult
{
    /**
     * @param  array<string, mixed>  $remote
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly array $remote = [],
    ) {}

    /**
     * @param  array<string, mixed>  $remote
     */
    public static function passed(string $message, array $remote = []): self
    {
        return new self(true, $message, $remote);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
