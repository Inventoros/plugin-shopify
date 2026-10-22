<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Shopify;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Kit\Exceptions\RateLimited;
use Inventoros\Plugins\Shopify\Kit\Exceptions\TransientError;
use Inventoros\Plugins\Shopify\Kit\Support\CostBudget;
use Inventoros\Plugins\Shopify\Kit\Support\RetryAfter;

/**
 * ShopifyClient over the Admin GraphQL API, pinned to API_VERSION.
 *
 * Rate limits: Shopify meters GraphQL by query cost from a leaky bucket
 * per store. Before each request the expected cost is reserved from a
 * local mirror of that bucket (CostBudget); after it, the bucket is
 * corrected from extensions.cost.throttleStatus. An empty bucket, a
 * THROTTLED error or an HTTP 429 raises RateLimited with the wait, and the
 * job re-queues itself instead of sleeping.
 *
 * Errors: 401/403 PermanentError(authentication), 404 and GraphQL or user
 * errors PermanentError, 5xx and network failures TransientError. The
 * access token is sent only in the X-Shopify-Access-Token header and never
 * appears in an exception message.
 */
final class HttpShopifyClient implements ShopifyClient
{
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly string $shopDomain,
        private readonly string $accessToken,
        private readonly CostBudget $budget,
        private readonly string $apiVersion = self::API_VERSION,
    ) {}

    public function shop(): array
    {
        $data = $this->query(Queries::SHOP, [], 2);
        $shop = $data['shop'] ?? [];

        return [
            'id' => (string) Gid::id($shop['id'] ?? null),
            'name' => (string) ($shop['name'] ?? ''),
            'domain' => (string) ($shop['myshopifyDomain'] ?? $this->shopDomain),
            'currency' => (string) ($shop['currencyCode'] ?? ''),
            'plan' => $shop['plan']['displayName'] ?? null,
        ];
    }

    public function locations(?string $after = null): Page
    {
        $data = $this->query(Queries::LOCATIONS, ['first' => self::PAGE_SIZE, 'after' => $after], 10);
        $connection = $data['locations'] ?? [];

        return new Page(
            array_map(fn (array $node) => [
                'id' => (string) Gid::id($node['id'] ?? null),
                'name' => (string) ($node['name'] ?? ''),
                'active' => (bool) ($node['isActive'] ?? true),
            ], $connection['nodes'] ?? []),
            $connection['pageInfo']['endCursor'] ?? null,
            (bool) ($connection['pageInfo']['hasNextPage'] ?? false),
        );
    }

    public function products(?string $after = null, ?string $updatedSince = null): Page
    {
        $data = $this->query(Queries::PRODUCTS, [
            'first' => self::PAGE_SIZE,
            'after' => $after,
            'query' => $updatedSince === null ? null : "updated_at:>='{$updatedSince}'",
        ], 260);
        $connection = $data['products'] ?? [];

        return new Page(
            array_map([Normalizer::class, 'product'], $connection['nodes'] ?? []),
            $connection['pageInfo']['endCursor'] ?? null,
            (bool) ($connection['pageInfo']['hasNextPage'] ?? false),
        );
    }

    public function inventoryLevels(array $inventoryItemIds): array
    {
        $levels = [];

        foreach (array_chunk(array_values(array_unique($inventoryItemIds)), 50) as $chunk) {
            $data = $this->query(Queries::INVENTORY_LEVELS, [
                'ids' => array_map(fn ($id) => Gid::make('InventoryItem', $id), $chunk),
            ], 120);

            foreach ($data['nodes'] ?? [] as $item) {
                if (! is_array($item) || ! isset($item['id'])) {
                    continue;
                }

                foreach ($item['inventoryLevels']['nodes'] ?? [] as $level) {
                    $available = 0;
                    foreach ($level['quantities'] ?? [] as $quantity) {
                        if (($quantity['name'] ?? null) === 'available') {
                            $available = (int) ($quantity['quantity'] ?? 0);
                        }
                    }

                    $levels[] = [
                        'inventory_item_id' => (string) Gid::id($item['id']),
                        'location_id' => (string) Gid::id($level['location']['id'] ?? null),
                        'available' => $available,
                        'updated_at' => $level['updatedAt'] ?? null,
                    ];
                }
            }
        }

        return $levels;
    }

    public function orders(?string $after = null, ?string $updatedSince = null, ?string $createdSince = null): Page
    {
        $filters = array_filter([
            $updatedSince === null ? null : "updated_at:>='{$updatedSince}'",
            $createdSince === null ? null : "created_at:>='{$createdSince}'",
        ]);

        $data = $this->query(Queries::ORDERS, [
            'first' => 25,
            'after' => $after,
            'query' => $filters === [] ? null : implode(' AND ', $filters),
        ], 300);
        $connection = $data['orders'] ?? [];

        return new Page(
            array_map([Normalizer::class, 'order'], $connection['nodes'] ?? []),
            $connection['pageInfo']['endCursor'] ?? null,
            (bool) ($connection['pageInfo']['hasNextPage'] ?? false),
        );
    }

    public function order(string $id): ?array
    {
        $data = $this->query(Queries::ORDER, ['id' => Gid::make('Order', $id)], 30);

        return is_array($data['order'] ?? null) ? Normalizer::order($data['order']) : null;
    }

    public function setInventoryQuantities(array $quantities, string $idempotencyKey): void
    {
        if ($quantities === []) {
            return;
        }

        $data = $this->query(Queries::SET_QUANTITIES, [
            'idempotencyKey' => $idempotencyKey,
            'input' => [
                'name' => 'available',
                'reason' => 'correction',
                'referenceDocumentUri' => 'inventoros://shopify/sync/'.$idempotencyKey,
                'quantities' => array_map(fn (array $row) => [
                    'inventoryItemId' => Gid::make('InventoryItem', $row['inventory_item_id']),
                    'locationId' => Gid::make('Location', $row['location_id']),
                    'quantity' => (int) $row['quantity'],
                    'changeFromQuantity' => null,
                ], array_values($quantities)),
            ],
        ], 10);

        $this->assertNoUserErrors($data['inventorySetQuantities']['userErrors'] ?? []);
    }

    public function fulfilmentOrders(string $orderId, string $locationId): array
    {
        $result = [];
        $after = null;
        do {
            $data = $this->query(FulfilmentQueries::ORDERS, ['id' => Gid::make('Order', $orderId), 'location' => Gid::make('Location', $locationId), 'after' => $after], 300);
            if (! is_array($data['order'] ?? null)) {
                throw new PermanentError('The Shopify order no longer exists or is not accessible.');
            }
            $page = $data['order']['fulfillmentOrders'] ?? [];
            foreach ($page['nodes'] ?? [] as $order) {
                if ($order['lineItems']['pageInfo']['hasNextPage'] ?? false) {
                    throw new PermanentError('This fulfillment order has more than 250 lines. Fulfil it in Shopify.');
                }
                $result[] = [
                    'id' => Gid::id($order['id']), 'status' => $order['status'], 'request_status' => $order['requestStatus'],
                    'location_id' => Gid::id($order['assignedLocation']['location']['id'] ?? null),
                    'lines' => array_map(fn ($line) => [
                        'id' => Gid::id($line['id']), 'line_id' => Gid::id($line['lineItem']['id']),
                        'inventory_item_id' => Gid::id($line['inventoryItemId'] ?? null), 'remaining' => (int) $line['remainingQuantity'],
                        'stocked' => ($line['variant']['inventoryItem']['inventoryLevel'] ?? null) !== null,
                    ], $order['lineItems']['nodes'] ?? []),
                ];
            }
            $more = (bool) ($page['pageInfo']['hasNextPage'] ?? false);
            $after = $page['pageInfo']['endCursor'] ?? null;
            if ($more && $after === null) {
                throw new TransientError('Shopify omitted the fulfilment order page cursor.');
            }
        } while ($more);

        return $result;
    }

    public function activateInventory(string $inventoryItemId, string $locationId, string $key): void
    {
        $data = $this->query(FulfilmentQueries::ACTIVATE, ['item' => Gid::make('InventoryItem', $inventoryItemId), 'location' => Gid::make('Location', $locationId), 'key' => $key], 10);
        $this->assertNoUserErrors($data['inventoryActivate']['userErrors'] ?? []);
    }

    public function moveFulfilmentOrder(string $id, string $locationId, array $lines): void
    {
        if ($lines === []) {
            throw new PermanentError('A shipment move must name its line quantities.');
        }
        $data = $this->query(FulfilmentQueries::MOVE, [
            'id' => Gid::make('FulfillmentOrder', $id), 'location' => Gid::make('Location', $locationId),
            'lines' => array_map(fn ($line) => ['id' => Gid::make('FulfillmentOrderLineItem', $line['id']), 'quantity' => $line['quantity']], $lines),
        ], 10);
        $this->assertNoUserErrors($data['fulfillmentOrderMove']['userErrors'] ?? []);
    }

    public function createFulfilment(array $fulfilment, string $message): string
    {
        $data = $this->query(FulfilmentQueries::CREATE, ['fulfillment' => $fulfilment, 'message' => $message], 10);
        $this->assertNoUserErrors($data['fulfillmentCreate']['userErrors'] ?? []);
        $id = Gid::id($data['fulfillmentCreate']['fulfillment']['id'] ?? null);
        if ($id === null) {
            throw new TransientError('Shopify did not return a fulfilment id.');
        }

        return $id;
    }

    public function updateProductTitle(string $productId, string $title): void
    {
        $data = $this->query(Queries::UPDATE_PRODUCT, [
            'product' => ['id' => Gid::make('Product', $productId), 'title' => $title],
        ], 10);

        $this->assertNoUserErrors($data['productUpdate']['userErrors'] ?? []);
    }

    public function updateVariantPrices(string $productId, array $variants): void
    {
        if ($variants === []) {
            return;
        }

        $data = $this->query(Queries::UPDATE_VARIANTS, [
            'productId' => Gid::make('Product', $productId),
            'variants' => array_map(fn (array $variant) => [
                'id' => Gid::make('ProductVariant', $variant['id']),
                'price' => (string) $variant['price'],
            ], array_values($variants)),
        ], 10);

        $this->assertNoUserErrors($data['productVariantsBulkUpdate']['userErrors'] ?? []);
    }

    public function subscribeWebhook(string $topic, string $callbackUrl): void
    {
        $data = $this->query(Queries::SUBSCRIBE_WEBHOOK, [
            'topic' => $topic,
            'subscription' => ['uri' => $callbackUrl, 'format' => 'JSON'],
        ], 10);

        $errors = $data['webhookSubscriptionCreate']['userErrors'] ?? [];
        $errors = array_filter($errors, fn (array $error) => ! str_contains(strtolower((string) ($error['message'] ?? '')), 'already been taken'));

        $this->assertNoUserErrors(array_values($errors));
    }

    /**
     * Run one GraphQL operation and return its `data`.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function query(string $query, array $variables, int $expectedCost): array
    {
        $this->budget->reserve($expectedCost);

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $this->accessToken,
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->connectTimeout(10)
                ->post($this->endpoint(), ['query' => $query, 'variables' => (object) array_filter($variables, fn ($v) => $v !== null)]);
        } catch (ConnectionException $error) {
            throw new TransientError('Could not reach Shopify: '.$this->clean($error->getMessage()));
        }

        $this->checkStatus($response);

        $body = $response->json();
        if (! is_array($body)) {
            throw new TransientError('Shopify returned a response that is not JSON (HTTP '.$response->status().').');
        }

        $this->observeCost($body);

        $errors = $body['errors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            $this->throwGraphqlErrors($errors, $body);
        }

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    private function endpoint(): string
    {
        return 'https://'.$this->shopDomain.'/admin/api/'.$this->apiVersion.'/graphql.json';
    }

    private function checkStatus(Response $response): void
    {
        $status = $response->status();

        if ($status === 429) {
            throw new RateLimited('Shopify rate limit reached (HTTP 429).', RetryAfter::seconds($response->header('Retry-After'), 2));
        }

        if ($status === 401 || $status === 403) {
            throw new PermanentError("Shopify refused the access token (HTTP {$status}). Check the token and the app's API scopes.", authentication: true);
        }

        if ($status === 402) {
            throw new PermanentError('The Shopify store is frozen or unpaid (HTTP 402).');
        }

        if ($status === 404) {
            throw new PermanentError('Shopify store or API version not found (HTTP 404). Check the shop domain.');
        }

        if ($status >= 500) {
            throw new TransientError("Shopify had a server error (HTTP {$status}).");
        }

        if ($status >= 400) {
            throw new PermanentError("Shopify rejected the request (HTTP {$status}).");
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function observeCost(array $body): void
    {
        $status = $body['extensions']['cost']['throttleStatus'] ?? null;

        if (is_array($status) && isset($status['currentlyAvailable'])) {
            $this->budget->observe(
                (float) $status['currentlyAvailable'],
                isset($status['maximumAvailable']) ? (float) $status['maximumAvailable'] : null,
                isset($status['restoreRate']) ? (float) $status['restoreRate'] : null,
            );
        }
    }

    /**
     * @param  array<int, mixed>  $errors
     * @param  array<string, mixed>  $body
     */
    private function throwGraphqlErrors(array $errors, array $body): void
    {
        foreach ($errors as $error) {
            if (($error['extensions']['code'] ?? null) === 'THROTTLED') {
                $cost = (float) ($body['extensions']['cost']['requestedQueryCost'] ?? 50);
                $status = $body['extensions']['cost']['throttleStatus'] ?? [];
                $available = (float) ($status['currentlyAvailable'] ?? 0);
                $rate = max(1.0, (float) ($status['restoreRate'] ?? 50));

                throw new RateLimited('Shopify throttled the request (query cost).', max(1, (int) ceil(($cost - $available) / $rate)));
            }

            if (in_array($error['extensions']['code'] ?? null, ['ACCESS_DENIED', 'UNAUTHORIZED'], true)) {
                throw new PermanentError('Shopify denied access: '.$this->clean((string) ($error['message'] ?? '')).' Check the app\'s API scopes.', authentication: true);
            }

            if (($error['extensions']['code'] ?? null) === 'INTERNAL_SERVER_ERROR') {
                throw new TransientError('Shopify had an internal error.');
            }
        }

        $messages = array_map(fn ($error) => is_array($error) ? (string) ($error['message'] ?? 'error') : (string) $error, $errors);

        throw new PermanentError('Shopify returned errors: '.$this->clean(implode('; ', array_slice($messages, 0, 3))));
    }

    /**
     * @param  array<int, array<string, mixed>>  $userErrors
     */
    private function assertNoUserErrors(array $userErrors): void
    {
        if ($userErrors === []) {
            return;
        }

        $messages = array_map(fn (array $error) => (string) ($error['message'] ?? 'error'), $userErrors);

        throw new PermanentError('Shopify rejected the change: '.$this->clean(implode('; ', array_slice($messages, 0, 3))));
    }

    private function clean(string $message): string
    {
        return mb_substr(str_replace($this->accessToken, '[redacted]', $message), 0, 500);
    }
}
