<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Fakes;

use Inventoros\Plugins\Shopify\Kit\Exceptions\TransientError;
use Inventoros\Plugins\Shopify\Shopify\Normalizer;
use Inventoros\Plugins\Shopify\Shopify\Page;
use Inventoros\Plugins\Shopify\Shopify\ShopifyClient;
use Throwable;

/**
 * A Shopify store in memory, loaded from the recorded GraphQL responses in
 * tests/Fixtures/graphql and read through the same Normalizer as the real
 * client. Records every call; failures can be scripted per method.
 */
final class FakeShopifyClient implements ShopifyClient
{
    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    /** @var array<int, array{quantities: array<int, array<string, mixed>>, key: string}> */
    public array $pushes = [];

    /** @var array<string, array<int, Throwable>> */
    private array $failures = [];

    /** @var array<string, mixed> */
    public array $shop;

    /** @var array<int, array<string, mixed>> */
    public array $locations = [];

    /** @var array<int, array<int, array<string, mixed>>> product pages */
    public array $productPages = [];

    /** @var array<int, array<string, mixed>> */
    public array $orders = [];

    /** @var array<int, array<string, mixed>> */
    public array $levels = [];

    /** @var array<int, string> */
    public array $subscriptions = [];

    public function __construct()
    {
        $shop = self::fixture('shop')['data']['shop'];
        $this->shop = ['id' => '77001', 'name' => $shop['name'], 'domain' => $shop['myshopifyDomain'], 'currency' => $shop['currencyCode'], 'plan' => $shop['plan']['displayName']];

        foreach (self::fixture('locations')['data']['locations']['nodes'] as $node) {
            $this->locations[] = ['id' => substr($node['id'], strrpos($node['id'], '/') + 1), 'name' => $node['name'], 'active' => $node['isActive']];
        }

        foreach (['products_page1', 'products_page2'] as $page) {
            $this->productPages[] = array_map([Normalizer::class, 'product'], self::fixture($page)['data']['products']['nodes']);
        }

        $this->orders = array_map([Normalizer::class, 'order'], self::fixture('orders')['data']['orders']['nodes']);

        foreach (self::fixture('inventory_levels')['data']['nodes'] as $item) {
            foreach ($item['inventoryLevels']['nodes'] as $level) {
                $this->levels[] = [
                    'inventory_item_id' => substr($item['id'], strrpos($item['id'], '/') + 1),
                    'location_id' => substr($level['location']['id'], strrpos($level['location']['id'], '/') + 1),
                    'available' => $level['quantities'][0]['quantity'],
                    'updated_at' => $level['updatedAt'],
                ];
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/graphql/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Throw these, one per call, before $method succeeds again.
     */
    public function failNext(string $method, Throwable ...$errors): self
    {
        $this->failures[$method] = array_merge($this->failures[$method] ?? [], $errors);

        return $this;
    }

    public function callsTo(string $method): int
    {
        return count(array_filter($this->calls, fn (array $call) => $call[0] === $method));
    }

    /**
     * Every quantity pushed, in order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pushedQuantities(): array
    {
        return array_merge(...array_map(fn (array $push) => $push['quantities'], $this->pushes ?: [['quantities' => []]]));
    }

    public function shop(): array
    {
        $this->record(__FUNCTION__, []);

        return $this->shop;
    }

    public function locations(?string $after = null): Page
    {
        $this->record(__FUNCTION__, [$after]);

        return new Page($this->locations);
    }

    public function products(?string $after = null, ?string $updatedSince = null): Page
    {
        $this->record(__FUNCTION__, [$after, $updatedSince]);

        $index = $after === null ? 0 : (int) str_replace('cursor-page-', '', $after);
        $items = $this->productPages[$index] ?? [];

        if ($updatedSince !== null) {
            $items = array_values(array_filter($items, fn ($p) => $p['updated_at'] >= $updatedSince));
        }

        $next = $index + 1 < count($this->productPages);

        return new Page($items, 'cursor-page-'.($index + 1), $next);
    }

    public function inventoryLevels(array $inventoryItemIds): array
    {
        $this->record(__FUNCTION__, [$inventoryItemIds]);

        return array_values(array_filter($this->levels, fn ($level) => in_array($level['inventory_item_id'], $inventoryItemIds, true)));
    }

    public function orders(?string $after = null, ?string $updatedSince = null, ?string $createdSince = null): Page
    {
        $this->record(__FUNCTION__, [$after, $updatedSince, $createdSince]);

        $items = $this->orders;

        if ($updatedSince !== null) {
            $items = array_values(array_filter($items, fn ($o) => $o['updated_at'] >= $updatedSince));
        }

        if ($createdSince !== null) {
            $items = array_values(array_filter($items, fn ($o) => strtotime($o['created_at']) >= strtotime($createdSince)));
        }

        return new Page($items, 'cursor-orders-1', false);
    }

    public function order(string $id): ?array
    {
        $this->record(__FUNCTION__, [$id]);

        foreach ($this->orders as $order) {
            if ($order['id'] === $id) {
                return $order;
            }
        }

        return null;
    }

    public function setInventoryQuantities(array $quantities, string $idempotencyKey): void
    {
        $this->record(__FUNCTION__, [$quantities, $idempotencyKey]);
        $this->pushes[] = ['quantities' => $quantities, 'key' => $idempotencyKey];
    }

    public function updateProductTitle(string $productId, string $title): void
    {
        $this->record(__FUNCTION__, [$productId, $title]);
    }

    public function updateVariantPrices(string $productId, array $variants): void
    {
        $this->record(__FUNCTION__, [$productId, $variants]);
    }

    public function subscribeWebhook(string $topic, string $callbackUrl): void
    {
        $this->record(__FUNCTION__, [$topic, $callbackUrl]);
        $this->subscriptions[] = $topic;
    }

    /**
     * @param  array<int, mixed>  $args
     */
    public array $fulfilmentOrderRows = [];

    public array $fulfilments = [];

    public bool $loseFulfilmentResponse = false;

    public function fulfilmentOrders(string $orderId, string $locationId): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return $this->fulfilmentOrderRows;
    }

    public function activateInventory(string $inventoryItemId, string $locationId, string $key): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function moveFulfilmentOrder(string $id, string $locationId, array $lines): void
    {
        $this->record(__FUNCTION__, func_get_args());
        foreach ($this->fulfilmentOrderRows as &$row) {
            if ($row['id'] === $id) {
                $row['location_id'] = $locationId;
                foreach ($row['lines'] as &$line) {
                    $line['stocked'] = true;
                }
            }
        }
    }

    public function createFulfilment(array $fulfilment, string $message): string
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->fulfilments[] = $fulfilment;
        if ($this->loseFulfilmentResponse) {
            throw new TransientError('Response lost');
        }

        return (string) (9000 + count($this->fulfilments));
    }

    private function record(string $method, array $args): void
    {
        $this->calls[] = [$method, $args];

        if (($this->failures[$method] ?? []) !== []) {
            throw array_shift($this->failures[$method]);
        }
    }
}
