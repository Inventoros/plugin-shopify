<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use App\Models\Order\Order;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\WebhookInboxEntry;
use Inventoros\Plugins\Shopify\Jobs\RegisterWebhooks;
use Inventoros\Plugins\Shopify\Kit\Webhooks\HmacSignature;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\TestCase;

/**
 * The webhook endpoint refuses anything not signed by the connection's own
 * secret for its own shop, stores nothing it refused, and handles the
 * app being uninstalled.
 */
final class WebhookSecurityTest extends TestCase
{
    use ShopifyWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        $this->mapLocations();
        $this->mapCatalog();
    }

    private function assertNothingStored(): void
    {
        $this->assertSame(0, WebhookInboxEntry::query()->count(), 'a refused delivery is never stored');
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(10, $this->bolt->fresh()->stock);
    }

    public function test_the_webhook_address_stores_subscribe_to_is_unchanged_since_1_0_0(): void
    {
        // Stores connected on 1.0.0 hold subscriptions to this address;
        // moving it would silently stop their deliveries.
        $this->assertSame(url('/webhooks/plugins/shopify/'.$this->store->id), RegisterWebhooks::callbackUrl($this->store));
        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk();
    }

    public function test_a_wrong_signature_is_rejected(): void
    {
        $this->webhook('orders/create', $this->payload('orders_create'), [], null, 'not-the-secret')->assertStatus(401);

        $this->assertNothingStored();
    }

    public function test_a_missing_signature_is_rejected(): void
    {
        $body = (string) json_encode($this->payload('orders_create'));

        $this->call('POST', '/webhooks/plugins/shopify/'.$this->store->id, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $this->store->shop_domain,
        ], $body)->assertStatus(401);

        $this->assertNothingStored();
    }

    public function test_a_body_changed_after_signing_is_rejected(): void
    {
        $body = (string) json_encode($this->payload('orders_create'));
        $signature = HmacSignature::sign($body, self::SECRET);
        $tampered = str_replace('"quantity":2', '"quantity":1', $body);

        $this->call('POST', '/webhooks/plugins/shopify/'.$this->store->id, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $this->store->shop_domain,
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $signature,
        ], $tampered)->assertStatus(401);

        $this->assertNothingStored();
    }

    public function test_a_delivery_for_another_shop_is_rejected(): void
    {
        $this->webhook('orders/create', $this->payload('orders_create'), ['X-Shopify-Shop-Domain' => 'other-store.myshopify.com'])->assertStatus(401);

        $this->assertNothingStored();
    }

    public function test_an_unknown_connection_is_not_found(): void
    {
        $this->call('POST', '/webhooks/plugins/shopify/999999', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertNotFound();
    }

    public function test_the_endpoint_needs_no_session_or_csrf_token_but_only_accepts_post(): void
    {
        $this->get('/webhooks/plugins/shopify/'.$this->store->id)->assertStatus(405);
        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk();
    }

    public function test_an_unhandled_topic_is_acknowledged_without_being_stored(): void
    {
        $this->webhook('customers/create', ['id' => 1])->assertOk();

        $this->assertSame(0, WebhookInboxEntry::query()->count());
    }

    public function test_app_uninstalled_disconnects_the_store_and_forgets_its_credentials(): void
    {
        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk();
        $this->webhook('app/uninstalled', $this->payload('app_uninstalled'))->assertOk();

        $store = $this->store->fresh();
        $this->assertSame(Connection::DISCONNECTED, $store->status);
        $this->assertNull($store->credentials);
        $this->assertSame(1, Order::withoutGlobalScopes()->count(), 'imported orders are kept');
        $this->assertNotNull($this->mapping('variant', '3001'), 'mappings are kept for a reconnect');

        // Nothing more is accepted for it.
        $this->webhook('orders/create', $this->payload('orders_create'), ['X-Shopify-Event-Id' => 'later'])->assertNotFound();
    }
}
