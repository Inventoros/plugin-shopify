<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use App\Jobs\WebhookDeliveryJob;
use App\Models\Role;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Plugins\PluginWebhookEventRegistry;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Support\Permissions;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\TestCase;
use Laravel\Sanctum\Sanctum;

/**
 * The shopify_sync_status MCP tool and the shopify.order_imported and
 * shopify.sync_failed webhook events.
 */
final class McpAndEventsTest extends TestCase
{
    use ShopifyWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        $this->mapLocations();
        $this->mapCatalog();
    }

    private function rpc(string $method, array $params = []): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params === [] ? (object) [] : $params,
        ])->assertOk();
    }

    /**
     * @return array<int, string>
     */
    private function toolNames(): array
    {
        $names = [];
        $cursor = null;
        $pages = 0;

        do {
            $response = $this->rpc('tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
            $names = array_merge($names, collect($response->json('result.tools'))->pluck('name')->all());
            $cursor = $response->json('result.nextCursor');
        } while ($cursor !== null && ++$pages < 20);

        return $names;
    }

    public function test_the_status_tool_reports_this_organizations_connections(): void
    {
        $viewer = $this->worker([Permissions::VIEW]);
        Sanctum::actingAs($viewer, ['*']);
        $this->connect([], $this->makeOrganization('Other'), null, 'other-store.myshopify.com');

        $this->assertContains('shopify_sync_status', $this->toolNames());

        $response = $this->rpc('tools/call', ['name' => 'shopify_sync_status', 'arguments' => (object) []]);
        $payload = json_decode((string) $response->json('result.content.0.text'), true);

        $this->assertCount(1, $payload['connections']);
        $this->assertSame('acme-store.myshopify.com', $payload['connections'][0]['shop_domain']);
        $this->assertSame('healthy', $payload['connections'][0]['state']);
        $this->assertArrayHasKey('queue_depth', $payload['connections'][0]);
        $this->assertArrayHasKey('error_rate', $payload['connections'][0]);
        $this->assertStringNotContainsString(self::TOKEN, (string) $response->getContent());
    }

    public function test_the_status_tool_is_hidden_from_users_without_the_permission(): void
    {
        Sanctum::actingAs($this->worker(['view_products']), ['*']);

        $this->assertNotContains('shopify_sync_status', $this->toolNames());
    }

    public function test_both_webhook_events_are_registered(): void
    {
        $registry = app(PluginWebhookEventRegistry::class);

        $this->assertTrue($registry->has('shopify.order_imported'));
        $this->assertTrue($registry->has('shopify.sync_failed'));
    }

    public function test_an_imported_order_is_announced_to_subscribed_webhooks(): void
    {
        Queue::fake([WebhookDeliveryJob::class]);
        $this->subscribe(['shopify.order_imported']);

        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk();

        $delivery = WebhookDelivery::query()->where('event', 'shopify.order_imported')->sole();
        $this->assertSame('5001', $delivery->payload['data']['shopify_order_id']);
        $this->assertSame('#1001', $delivery->payload['data']['shopify_order_name']);
        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function test_a_failure_that_gives_up_is_announced(): void
    {
        Queue::fake([WebhookDeliveryJob::class]);
        $this->subscribe(['shopify.sync_failed']);
        $this->shopify->failNext('order', new PermanentError('Shopify order 9 no longer exists.'));

        dispatch(new \Inventoros\Plugins\Shopify\Jobs\ImportOrder($this->store->id, '9'));

        $delivery = WebhookDelivery::query()->where('event', 'shopify.sync_failed')->sole();
        $this->assertSame('import_order', $delivery->payload['data']['kind']);
        $this->assertStringContainsString('no longer exists', $delivery->payload['data']['error']);
    }

    /**
     * @param  array<int, string>  $events
     */
    private function subscribe(array $events): void
    {
        Webhook::create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->admin->id,
            'name' => 'Ops',
            'url' => 'https://hooks.example.com/inventoros',
            'events' => $events,
            'is_active' => true,
        ]);
    }
}
