<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Kit\Exceptions\TransientError;
use Inventoros\Plugins\Shopify\Kit\Support\CostBudget;
use Inventoros\Plugins\Shopify\Shopify\HttpShopifyClient;
use Inventoros\Plugins\Shopify\Tests\Fakes\FakeShopifyClient;
use Inventoros\Plugins\Shopify\Tests\TestCase;

/**
 * The real GraphQL client against the recorded responses: what it sends,
 * how it reads each response, and how it reports failures.
 */
final class HttpClientTest extends TestCase
{
    private const ENDPOINT = 'https://acme-store.myshopify.com/admin/api/2026-07/graphql.json';

    private const TOKEN = 'shpat_http_client_token_abcdef';

    /**
     * @return array<string, mixed>
     */
    private static function variables(Request $request): array
    {
        return json_decode($request->body(), true)['variables'] ?? [];
    }

    private function client(): HttpShopifyClient
    {
        $budget = CostBudget::for(4242);
        $budget->reset();

        return new HttpShopifyClient('acme-store.myshopify.com', self::TOKEN, $budget);
    }

    public function test_it_sends_the_token_header_to_the_pinned_version_and_reads_the_shop(): void
    {
        Http::fake([self::ENDPOINT => Http::response(FakeShopifyClient::fixture('shop'))]);

        $shop = $this->client()->shop();

        $this->assertSame(['id' => '77001', 'name' => 'Acme Store', 'domain' => 'acme-store.myshopify.com', 'currency' => 'USD', 'plan' => 'Basic', 'scopes' => []], $shop);
        Http::assertSent(fn (Request $request) => $request->url() === self::ENDPOINT
            && $request->hasHeader('X-Shopify-Access-Token', self::TOKEN)
            && ! $request->hasHeader('Authorization'));
    }

    public function test_it_reads_products_with_variants_and_pages(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(FakeShopifyClient::fixture('products_page1'))
            ->push(FakeShopifyClient::fixture('products_page2'))]);

        $client = $this->client();
        $first = $client->products();
        $second = $client->products($first->endCursor, '2026-10-01T00:00:00Z');

        $this->assertTrue($first->hasNextPage);
        $this->assertSame('cursor-page-1', $first->endCursor);
        $this->assertSame('2001', $first->items[0]['id']);
        $this->assertSame(['id' => '3001', 'product_id' => '2001', 'sku' => 'BOLT-1', 'title' => 'Default Title', 'price' => '4.00', 'barcode' => '0123456789012', 'inventory_item_id' => '4001', 'options' => ['Title' => 'Default Title']], $first->items[0]['variants'][0]);
        $this->assertFalse($second->hasNextPage);
        $this->assertNull($second->items[0]['variants'][0]['sku'], 'an empty SKU is no SKU');

        Http::assertSent(fn (Request $request) => (self::variables($request)['after'] ?? null) === 'cursor-page-1'
            && self::variables($request)['query'] === "updated_at:>='2026-10-01T00:00:00Z'");
    }

    public function test_it_reads_orders_and_inventory_levels(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(FakeShopifyClient::fixture('orders'))
            ->push(FakeShopifyClient::fixture('inventory_levels'))]);

        $client = $this->client();
        $orders = $client->orders(null, null, '2026-10-01T00:00:00Z');
        $levels = $client->inventoryLevels(['4001', '4002']);

        $order = $orders->items[0];
        $this->assertSame('5001', $order['id']);
        $this->assertSame('#1001', $order['name']);
        $this->assertSame('Ada Lovelace', $order['customer_name']);
        $this->assertSame('5.00', $order['shipping']);
        $this->assertSame(['id' => '6002', 'variant_id' => '3002', 'product_id' => '2002', 'sku' => 'TEE-S', 'title' => 'T-shirt - S', 'quantity' => 1, 'price' => '15.00', 'discount' => '1.50'], $order['line_items'][1]);

        $this->assertContains(['inventory_item_id' => '4001', 'location_id' => '1002', 'available' => 3, 'updated_at' => '2026-10-02T08:00:00Z'], $levels);
        Http::assertSent(fn (Request $request) => (self::variables($request)['ids'] ?? null) === ['gid://shopify/InventoryItem/4001', 'gid://shopify/InventoryItem/4002']);
    }

    public function test_a_webhook_order_and_a_graphql_order_normalize_identically(): void
    {
        Http::fake([self::ENDPOINT => Http::response(FakeShopifyClient::fixture('orders'))]);
        $fromApi = $this->client()->orders()->items[0];

        $fromWebhook = \Inventoros\Plugins\Shopify\Shopify\Normalizer::order(json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/webhooks/orders_create.json'), true));

        foreach (['id', 'name', 'customer_name', 'currency', 'tax', 'shipping', 'email'] as $key) {
            $this->assertSame($fromApi[$key], $fromWebhook[$key], $key);
        }
        $this->assertSame(
            array_map(fn ($l) => [$l['variant_id'], $l['quantity'], $l['price'], $l['discount']], $fromApi['line_items']),
            array_map(fn ($l) => [$l['variant_id'], $l['quantity'], $l['price'], $l['discount']], $fromWebhook['line_items']),
        );
    }

    public function test_quantities_are_set_as_absolute_values_with_an_idempotency_key(): void
    {
        Http::fake([self::ENDPOINT => Http::response(FakeShopifyClient::fixture('set_quantities'))]);

        $this->client()->setInventoryQuantities([['inventory_item_id' => '4001', 'location_id' => '1001', 'quantity' => 12]], 'key-1');

        Http::assertSent(function (Request $request) {
            $input = self::variables($request)['input'];

            return self::variables($request)['idempotencyKey'] === 'key-1'
                && $input['name'] === 'available'
                && $input['quantities'][0] === ['inventoryItemId' => 'gid://shopify/InventoryItem/4001', 'locationId' => 'gid://shopify/Location/1001', 'quantity' => 12, 'changeFromQuantity' => null];
        });
    }

    public function test_failures_are_classified_and_never_echo_the_token(): void
    {
        $cases = [
            [PermanentError::class, 'refused the access token'],
            [PermanentError::class, 'not found'],
            [TransientError::class, 'server error'],
            [PermanentError::class, 'returned errors'],
        ];

        Http::fake([self::ENDPOINT => Http::sequence()
            ->push('nope', 401)
            ->push('gone', 404)
            ->push('down', 502)
            ->push(['errors' => [['message' => 'Field x does not exist '.self::TOKEN]]], 200)]);

        foreach ($cases as [$class, $message]) {
            try {
                $this->client()->shop();
                $this->fail("expected {$class}");
            } catch (PermanentError|TransientError $error) {
                $this->assertInstanceOf($class, $error);
                $this->assertStringContainsString($message, $error->getMessage());
                $this->assertStringNotContainsString(self::TOKEN, $error->getMessage());
            }
        }
    }

    public function test_the_cost_budget_follows_the_reported_throttle_status(): void
    {
        Http::fake([self::ENDPOINT => Http::response(FakeShopifyClient::fixture('locations'))]);

        $this->client()->locations();

        $this->assertEqualsWithDelta(1996, CostBudget::for(4242)->available(), 5);
    }
}
