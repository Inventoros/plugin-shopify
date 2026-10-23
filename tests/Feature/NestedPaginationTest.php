<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Kit\Exceptions\RateLimited;
use Inventoros\Plugins\Shopify\Kit\Support\CostBudget;
use Inventoros\Plugins\Shopify\Shopify\HttpShopifyClient;
use Inventoros\Plugins\Shopify\Tests\Fakes\FakeShopifyClient;
use Inventoros\Plugins\Shopify\Tests\TestCase;

final class NestedPaginationTest extends TestCase
{
    private function client(): HttpShopifyClient
    {
        return new HttpShopifyClient('acme-store.myshopify.com', 'test-token', CostBudget::for(12345));
    }

    private function response(array $data): array
    {
        return ['data' => $data, 'extensions' => ['cost' => ['throttleStatus' => [
            'currentlyAvailable' => 10000, 'maximumAvailable' => 10000, 'restoreRate' => 100,
        ]]]];
    }

    public function test_all_variants_are_loaded_and_a_throttle_retries_the_same_nested_page(): void
    {
        Sleep::fake();
        $fixture = FakeShopifyClient::fixture('products_page1');
        $node = $fixture['data']['products']['nodes'][0];
        $node['variants']['pageInfo'] = ['hasNextPage' => true, 'endCursor' => 'variants-50'];
        $extra = $node['variants']['nodes'][0];
        $extra['id'] = 'gid://shopify/ProductVariant/9999';
        Http::fake(['*' => Http::sequence()
            ->push($this->response(['products' => ['nodes' => [$node], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'parent-3']]]))
            ->push([], 429, ['Retry-After' => '2'])
            ->push($this->response(['product' => ['variants' => ['nodes' => [$extra], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'end']]]]))]);

        $page = $this->client()->products();
        $this->assertSame('9999', $page->items[0]['variants'][count($node['variants']['nodes'])]['id']);
        $this->assertSame('parent-3', $page->endCursor);
        $this->assertTrue($page->hasNextPage);
        $requests = Http::recorded()->map(fn ($pair) => json_decode($pair[0]->body(), true))->all();
        $this->assertSame(3, $requests[0]['variables']['first']);
        $this->assertSame('variants-50', $requests[1]['variables']['after']);
        $this->assertSame($requests[1]['variables'], $requests[2]['variables']);
        Sleep::assertSleptTimes(1);
        Http::assertSentCount(3);
    }

    public function test_order_lines_and_inventory_locations_are_not_truncated(): void
    {
        $order = FakeShopifyClient::fixture('orders')['data']['orders']['nodes'][0];
        $line = $order['lineItems']['nodes'][0];
        $line['id'] = 'gid://shopify/LineItem/9999';
        $order['lineItems']['pageInfo'] = ['hasNextPage' => true, 'endCursor' => 'lines-50'];
        $inventory = FakeShopifyClient::fixture('inventory_levels')['data']['nodes'][0];
        $level = $inventory['inventoryLevels']['nodes'][0];
        $level['location']['id'] = 'gid://shopify/Location/9999';
        $inventory['inventoryLevels']['pageInfo'] = ['hasNextPage' => true, 'endCursor' => 'locations-25'];
        Http::fake(['*' => Http::sequence()
            ->push($this->response(['orders' => ['nodes' => [$order]]]))
            ->push($this->response(['order' => ['lineItems' => ['nodes' => [$line], 'pageInfo' => ['hasNextPage' => false]]]]))
            ->push($this->response(['nodes' => [$inventory]]))
            ->push($this->response(['inventoryItem' => ['inventoryLevels' => ['nodes' => [$level], 'pageInfo' => ['hasNextPage' => false]]]]))]);

        $client = $this->client();
        $orders = $client->orders();
        $this->assertSame('9999', $orders->items[0]['line_items'][count($order['lineItems']['nodes'])]['id']);
        $levels = $client->inventoryLevels(['4001']);
        $this->assertSame('9999', $levels[count($inventory['inventoryLevels']['nodes'])]['location_id']);
        Http::assertSent(fn ($request) => (json_decode($request->body(), true)['variables']['first'] ?? null) === 2);
        Http::assertSent(fn ($request) => (json_decode($request->body(), true)['variables']['after'] ?? null) === 'locations-25');
    }

    public function test_a_broken_nested_cursor_refuses_a_partial_record(): void
    {
        $order = FakeShopifyClient::fixture('orders')['data']['orders']['nodes'][0];
        $order['lineItems']['pageInfo'] = ['hasNextPage' => true, 'endCursor' => 'repeated'];
        Http::fake(['*' => Http::sequence()
            ->push($this->response(['order' => $order]))
            ->push($this->response(['order' => ['lineItems' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'repeated']]]]))]);
        $this->expectException(PermanentError::class);
        $this->expectExceptionMessage('refusing a partial record');
        $this->client()->order('5001');
    }

    public function test_long_throttles_return_to_the_existing_job_retry_without_sleeping(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '120'])]);
        try {
            $this->client()->products();
            $this->fail('Expected rate limit.');
        } catch (RateLimited $error) {
            $this->assertSame(120, $error->retryAfter);
        }
        Sleep::assertNeverSlept();
        Http::assertSentCount(1);
    }
}
