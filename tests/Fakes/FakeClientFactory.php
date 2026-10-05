<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Fakes;

use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Shopify\ClientFactory;
use Inventoros\Plugins\Shopify\Shopify\ShopifyClient;

/**
 * Hands every connection its own fake store (keyed by connection id), so
 * tenant tests can tell two stores apart. The default fake serves any
 * connection without one of its own.
 */
final class FakeClientFactory extends ClientFactory
{
    /** @var array<int, FakeShopifyClient> */
    public array $stores = [];

    /** @var array<int, int> connection ids served, in order */
    public array $served = [];

    public function __construct(public FakeShopifyClient $default) {}

    public function make(Connection $connection): ShopifyClient
    {
        $this->served[] = $connection->id;

        return $this->stores[$connection->id] ?? $this->default;
    }
}
