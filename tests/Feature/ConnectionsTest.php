<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Kit\Models\SyncLog;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\TestCase;

/**
 * Connecting a store: validation, encrypted credentials that never reach
 * the browser, the background connection test and what follows it.
 */
final class ConnectionsTest extends TestCase
{
    use ShopifyWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        $this->store->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Acme Store',
            'shop_domain' => 'https://Acme-Store.myshopify.com/admin',
            'access_token' => self::TOKEN,
            'webhook_secret' => self::SECRET,
            'stock_source' => 'inventoros',
            'product_source' => 'shopify',
            'import_orders' => true,
        ], $overrides);
    }

    public function test_connecting_stores_encrypted_credentials_and_tests_them_in_the_background(): void
    {
        config(['app.url' => 'https://inventory.example.com']);
        url()->forceRootUrl('https://inventory.example.com');
        url()->forceScheme('https');

        $this->actingAs($this->admin)->post(route('plg.shopify.actions.connections.store'), $this->form())->assertSessionHasNoErrors()->assertRedirect();

        $connection = Connection::query()->sole();
        $this->assertSame('acme-store.myshopify.com', $connection->shop_domain);
        $this->assertSame(Connection::CONNECTED, $connection->status, 'the queued test ran and passed');
        $this->assertSame('Acme Store', $connection->remote['shop_name']);
        $this->assertSame($this->admin->id, $connection->acting_user_id);
        $this->assertSame(self::TOKEN, $connection->credential('access_token'));

        $raw = (string) DB::table('plg_shopify_connections')->value('credentials');
        $this->assertStringNotContainsString(self::TOKEN, $raw, 'encrypted at rest');
        $this->assertStringNotContainsString(self::SECRET, $raw);

        $this->assertSame(['ORDERS_CREATE', 'ORDERS_UPDATED', 'ORDERS_CANCELLED', 'PRODUCTS_CREATE', 'PRODUCTS_UPDATE', 'INVENTORY_LEVELS_UPDATE', 'APP_UNINSTALLED'], $this->shopify->subscriptions);
        $callback = collect($this->shopify->calls)->firstWhere(0, 'subscribeWebhook')[1][1];
        $this->assertSame('https://inventory.example.com/webhooks/plugins/shopify/'.$connection->id, $callback);
        $this->assertSame(2, Mapping::query()->where('entity', 'location')->count(), 'locations read after the test');
    }

    public function test_webhooks_are_not_registered_on_a_non_public_address(): void
    {
        $this->actingAs($this->admin)->post(route('plg.shopify.actions.connections.store'), $this->form())->assertSessionHasNoErrors();

        $this->assertSame([], $this->shopify->subscriptions);
        $this->assertTrue(SyncLog::query()->where('action', 'webhooks')->where('message', 'like', '%public https address%')->exists());
    }

    public function test_a_failing_test_leaves_the_connection_in_error_without_leaking_the_token(): void
    {
        $this->shopify->failNext('shop', new PermanentError('Shopify refused the access token (HTTP 401).', authentication: true));

        $this->actingAs($this->admin)->post(route('plg.shopify.actions.connections.store'), $this->form())->assertSessionHasNoErrors();

        $connection = Connection::query()->sole();
        $this->assertSame(Connection::ERROR, $connection->status);
        $this->assertStringContainsString('refused the access token', $connection->last_error);
        $this->assertSame([], $this->shopify->subscriptions);
    }

    public function test_only_myshopify_domains_are_accepted(): void
    {
        foreach (['evil.example.com', '127.0.0.1', 'acme.myshopify.com.evil.io', 'http://10.0.0.1/admin', ''] as $domain) {
            $this->actingAs($this->admin)
                ->post(route('plg.shopify.actions.connections.store'), $this->form(['shop_domain' => $domain]))
                ->assertSessionHasErrors('shop_domain');
        }

        $this->assertSame(0, Connection::query()->count());
    }

    public function test_a_bare_store_handle_gets_the_myshopify_suffix(): void
    {
        $this->actingAs($this->admin)->post(route('plg.shopify.actions.connections.store'), $this->form(['shop_domain' => 'acme-store']))->assertSessionHasNoErrors();

        $this->assertSame('acme-store.myshopify.com', Connection::query()->sole()->shop_domain);
    }

    public function test_the_same_store_cannot_be_connected_twice_by_one_organization(): void
    {
        $this->actingAs($this->admin)->post(route('plg.shopify.actions.connections.store'), $this->form())->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('plg.shopify.actions.connections.store'), $this->form())->assertSessionHasErrors('shop_domain');
    }

    public function test_updating_settings_keeps_credentials_left_blank_and_never_changes_the_shop(): void
    {
        $this->actingAs($this->admin)->post(route('plg.shopify.actions.connections.store'), $this->form())->assertSessionHasNoErrors();
        $connection = Connection::query()->sole();

        $this->actingAs($this->admin)->post(route('plg.shopify.actions.connections.update', $connection), $this->form([
            'name' => 'Renamed',
            'shop_domain' => 'other-store.myshopify.com',
            'access_token' => '',
            'webhook_secret' => '',
            'stock_source' => 'shopify',
            'product_source' => 'inventoros',
            'import_orders' => false,
        ]))->assertSessionHasNoErrors();

        $connection->refresh();
        $this->assertSame('Renamed', $connection->name);
        $this->assertSame('acme-store.myshopify.com', $connection->shop_domain);
        $this->assertSame(self::TOKEN, $connection->credential('access_token'));
        $this->assertSame('shopify', $connection->settings['stock_source']);
        $this->assertSame('inventoros', $connection->settings['product_source']);
        $this->assertFalse($connection->settings['import_orders']);
    }

    public function test_the_page_never_receives_credentials(): void
    {
        $this->actingAs($this->admin)->post(route('plg.shopify.actions.connections.store'), $this->form())->assertSessionHasNoErrors();

        $response = $this->actingAs($this->admin)->get('/p/shopify')->assertOk();

        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $response->assertInertia(fn ($page) => $page
            ->component('Plugin::shopify/Overview')
            ->where('connections.0.has_access_token', true)
            ->where('connections.0.health.state', 'healthy')
            ->has('connections.0.health.queue_depth'));
    }

    public function test_removing_a_connection_keeps_the_orders_it_imported(): void
    {
        $this->actingAs($this->admin)->post(route('plg.shopify.actions.connections.store'), $this->form(['orders_since' => '2026-01-01']))->assertSessionHasNoErrors();
        $connection = Connection::query()->sole();
        $this->mapLocations($connection);
        $this->mapCatalog($connection);
        $this->webhook('orders/create', $this->payload('orders_create'), [], $connection)->assertOk();

        $this->actingAs($this->admin)->post(route('plg.shopify.actions.connections.destroy', $connection))->assertRedirect(route('plg.shopify.overview'));

        $this->assertSame(0, Connection::query()->count());
        $this->assertSame(0, Mapping::query()->count());
        $this->assertSame(1, \App\Models\Order\Order::withoutGlobalScopes()->where('source', 'shopify')->count());
        $this->assertSame(8, $this->bolt->fresh()->stock);
    }
}
