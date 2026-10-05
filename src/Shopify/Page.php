<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Shopify;

/**
 * One page of a cursor-paginated connection.
 *
 * @template T
 */
final class Page
{
    /**
     * @param  array<int, T>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $endCursor = null,
        public readonly bool $hasNextPage = false,
    ) {}
}
