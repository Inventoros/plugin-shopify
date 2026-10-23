<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Shopify\AccessTokens;
use Inventoros\Plugins\Shopify\Shopify\ClientFactory;
use Inventoros\Plugins\Shopify\Shopify\ShopifyConnectionTester;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\Fakes\FakeShopifyClient;
use Inventoros\Plugins\Shopify\Tests\TestCase;

final class ClientCredentialsTest extends TestCase
{
    use ShopifyWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function test_tokens_are_encrypted_reused_and_renewed_before_expiry(): void
    {
        $this->store->forceFill(['settings' => ['auth_mode' => 'client_credentials'],
            'credentials' => ['client_id' => 'app-client-123', 'webhook_secret' => self::SECRET]])->save();
        Http::fake(['*' => Http::sequence()
            ->push(['access_token' => 'minted-token-one', 'expires_in' => 86399])
            ->push(['access_token' => 'minted-token-two', 'expires_in' => 86399])]);
        $tokens = app(AccessTokens::class);
        $this->assertSame('minted-token-one', $tokens->token($this->store));
        $this->assertSame('minted-token-one', $tokens->token($this->store->fresh()));
        Http::assertSentCount(1);
        $raw = DB::table('plg_shopify_connections')->where('id', $this->store->id)->value('credentials');
        $this->assertStringNotContainsString('minted-token-one', $raw);
        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->travel(24)->hours();
        $this->assertSame('minted-token-two', $tokens->token($this->store->fresh()));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://acme-store.myshopify.com/admin/oauth/access_token'
            && $request['grant_type'] === 'client_credentials' && $request['client_secret'] === self::SECRET);
    }

    public function test_manual_tokens_make_no_exchange_and_bad_app_credentials_are_sanitized(): void
    {
        $this->assertSame(self::TOKEN, app(AccessTokens::class)->token($this->store));
        Http::assertNothingSent();
        $this->store->forceFill(['settings' => ['auth_mode' => 'client_credentials'],
            'credentials' => ['client_id' => 'app-id', 'webhook_secret' => self::SECRET]])->save();
        Http::fake(['*' => Http::response(['error_description' => self::SECRET], 400)]);
        try {
            app(AccessTokens::class)->token($this->store);
            $this->fail('Expected credential rejection.');
        } catch (PermanentError $error) {
            $this->assertStringContainsString('same Shopify organization', $error->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $error->getMessage());
        }
    }

    public function test_a_long_running_client_uses_the_renewed_token_on_its_next_page(): void
    {
        $this->store->forceFill(['settings' => ['auth_mode' => 'client_credentials'],
            'credentials' => ['client_id' => 'app-client-123', 'webhook_secret' => self::SECRET]])->save();
        Http::fake([
            '*/admin/oauth/access_token' => Http::sequence()
                ->push(['access_token' => 'token-before-expiry', 'expires_in' => 86399])
                ->push(['access_token' => 'token-after-expiry', 'expires_in' => 86399]),
            '*/graphql.json' => Http::response(FakeShopifyClient::fixture('shop')),
        ]);
        $client = (new ClientFactory)->make($this->store);
        $client->shop();
        $this->travel(24)->hours();
        $client->shop();
        $headers = Http::recorded(fn ($request) => str_ends_with($request->url(), 'graphql.json'))
            ->map(fn ($pair) => $pair[0]->header('X-Shopify-Access-Token')[0])->values()->all();
        $this->assertSame(['token-before-expiry', 'token-after-expiry'], $headers);
    }

    public function test_client_mode_saves_without_a_manual_token_and_keeps_blank_secrets(): void
    {
        Queue::fake();
        $this->actingAs($this->admin)->post(route('plg.shopify.actions.connections.update', $this->store->id), [
            'name' => 'Own app', 'auth_mode' => 'client_credentials', 'client_id' => 'app-123',
            'webhook_secret' => '', 'stock_source' => 'inventoros', 'product_source' => 'shopify', 'import_orders' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->store->fresh()->credential('access_token'));
        $this->assertSame('app-123', $this->store->fresh()->credential('client_id'));
        $this->assertSame(self::SECRET, $this->store->fresh()->credential('webhook_secret'));
        $this->assertSame('client_credentials', $this->store->fresh()->setting('auth_mode'));
    }

    public function test_validation_never_flashes_credentials_or_client_ids(): void
    {
        $this->actingAs($this->admin)->post(route('plg.shopify.actions.connections.store'), [
            'name' => '', 'access_token' => self::TOKEN, 'webhook_secret' => self::SECRET, 'client_id' => 'private-client-id',
        ])->assertRedirect()->assertSessionHasErrors('name');
        foreach (['access_token', 'webhook_secret', 'client_id'] as $field) {
            $this->assertNull(session()->getOldInput($field));
        }
    }

    public function test_connection_tests_name_missing_scopes_for_enabled_directions(): void
    {
        $this->shopify->shop['scopes'] = ['read_products', 'read_inventory', 'read_locations'];
        $result = app(ShopifyConnectionTester::class)->test($this->store);
        $this->assertFalse($result->ok);
        $this->assertStringContainsString('write_inventory', $result->message);
        $this->assertStringContainsString('read_orders', $result->message);
        $this->assertStringNotContainsString('write_products', $result->message);
        $this->store->forceFill(['settings' => ['stock_source' => 'shopify', 'product_source' => 'shopify', 'import_orders' => false]])->save();
        $this->assertTrue(app(ShopifyConnectionTester::class)->test($this->store)->ok);
    }
}
