<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use Inventoros\Plugins\Shopify\Kit\Models\WebhookInboxEntry;
use Inventoros\Plugins\Shopify\Support\Permissions;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\Fakes\FakeShopifyClient;
use Inventoros\Plugins\Shopify\Tests\TestCase;

/**
 * Who may see and change what, and two organizations with two stores
 * that never see or touch each other's data.
 */
final class PermissionsAndIsolationTest extends TestCase
{
    use ShopifyWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        $this->mapLocations();
        $this->mapCatalog();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/shopify')->assertRedirect();
        $this->post(route('shopify.actions.connections.sync', $this->store), ['resource' => 'all'])->assertRedirect();
        $this->assertSame(0, $this->shopify->callsTo('products'));
    }

    public function test_a_member_without_the_permissions_sees_nothing_and_changes_nothing(): void
    {
        $user = $this->worker(['view_products', 'view_orders']);

        foreach (['/shopify', '/shopify/mappings', '/shopify/activity'] as $page) {
            $this->actingAs($user)->get($page)->assertForbidden();
        }

        $this->actingAs($user)->post(route('shopify.actions.connections.sync', $this->store), ['resource' => 'all'])->assertForbidden();
        $this->actingAs($user)->post(route('shopify.actions.connections.store'), ['name' => 'x'])->assertForbidden();
    }

    public function test_view_only_users_see_the_pages_but_cannot_change_anything(): void
    {
        $viewer = $this->worker([Permissions::VIEW]);

        $this->actingAs($viewer)->get('/shopify')->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false));
        $this->actingAs($viewer)->get('/shopify/mappings')->assertOk();
        $this->actingAs($viewer)->get('/shopify/activity')->assertOk();

        $this->actingAs($viewer)->post(route('shopify.actions.connections.sync', $this->store), ['resource' => 'all'])->assertForbidden();
        $this->actingAs($viewer)->post(route('shopify.actions.mappings.ignore', $this->mapping('variant', '3001')))->assertForbidden();
        $this->actingAs($viewer)->post(route('shopify.actions.connections.destroy', $this->store))->assertForbidden();
        $this->assertNotNull($this->store->fresh());
    }

    public function test_managers_can_act(): void
    {
        $manager = $this->worker([Permissions::MANAGE]);

        $this->actingAs($manager)->get('/shopify')->assertOk()->assertInertia(fn ($page) => $page->where('canManage', true));
        $this->actingAs($manager)->post(route('shopify.actions.connections.sync', $this->store), ['resource' => 'products'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertGreaterThan(0, $this->shopify->callsTo('products'));
    }

    public function test_another_organization_cannot_read_or_change_this_ones_records(): void
    {
        $other = $this->makeOrganization('Other');
        $intruder = $this->makeUser($other, 'admin');
        $letter = DeadLetter::create([
            'organization_id' => $this->organization->id, 'connection_id' => $this->store->id, 'kind' => 'push_inventory',
            'payload' => ['job' => 'x', 'args' => []], 'error' => 'boom', 'failed_at' => now(),
        ]);

        $this->actingAs($intruder)->get('/shopify')->assertOk()->assertInertia(fn ($page) => $page->has('connections', 0));
        $this->actingAs($intruder)->get('/shopify/mappings?connection='.$this->store->id)->assertOk()->assertInertia(fn ($page) => $page->where('connection', null)->has('rows', 0));
        $this->actingAs($intruder)->get('/shopify/activity?connection='.$this->store->id.'&tab=failed')->assertOk()->assertInertia(fn ($page) => $page->has('deadLetters', 0));

        $this->actingAs($intruder)->post(route('shopify.actions.connections.sync', $this->store), ['resource' => 'all'])->assertNotFound();
        $this->actingAs($intruder)->post(route('shopify.actions.connections.update', $this->store), ['name' => 'Mine'])->assertNotFound();
        $this->actingAs($intruder)->post(route('shopify.actions.connections.destroy', $this->store))->assertNotFound();
        $this->actingAs($intruder)->post(route('shopify.actions.mappings.link', $this->mapping('variant', '3001')), ['sku' => 'BOLT-1'])->assertNotFound();
        $this->actingAs($intruder)->post(route('shopify.actions.failed.retry', $letter))->assertNotFound();

        $this->assertSame('Acme Store', $this->store->fresh()->name);
    }

    public function test_two_organizations_with_two_stores_sync_independently(): void
    {
        $otherOrg = $this->makeOrganization('Beta');
        $otherAdmin = $this->makeUser($otherOrg, 'admin');
        $otherMain = $this->warehouse('Main', $otherOrg);
        $this->warehouse('Annex', $otherOrg);
        $otherBin = $this->bin('B-01', $otherMain);
        $otherBolt = $this->product('BETA-BOLT-1', 'Beta bolt', 40, $otherBin, $otherOrg);
        $otherStore = $this->connect([], $otherOrg, $otherAdmin, 'beta-store.myshopify.com');

        // Beta's store sells the same Shopify ids with Beta's own SKU.
        $betaShop = new FakeShopifyClient;
        $betaShop->productPages[0][0]['variants'][0]['sku'] = 'BETA-BOLT-1';
        $this->clients->stores[$otherStore->id] = $betaShop;

        $this->mapLocations($otherStore);
        $saved = $this->shopify;
        $this->shopify = $betaShop;
        $this->mapCatalog($otherStore);
        $this->shopify = $saved;

        $this->assertSame($otherBolt->id, $this->mapping('variant', '3001', $otherStore)->internal_id);
        $this->assertSame($this->bolt->id, $this->mapping('variant', '3001')->internal_id);

        // Acme's secret does not open Beta's inbox.
        $this->webhook('orders/create', $this->payload('orders_create'), ['X-Shopify-Shop-Domain' => 'beta-store.myshopify.com'], $otherStore, self::SECRET.'-wrong')->assertStatus(401);

        // A Beta order lands in Beta only.
        $order = $this->payload('orders_create');
        $order['line_items'] = [$order['line_items'][0]];
        $this->webhook('orders/create', $order, [], $otherStore)->assertOk();

        $this->assertSame(1, Order::withoutGlobalScopes()->where('organization_id', $otherOrg->id)->count());
        $this->assertSame(0, Order::withoutGlobalScopes()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(38, $otherBolt->fresh()->stock);
        $this->assertSame(10, $this->bolt->fresh()->stock);
        $this->assertSame($otherOrg->id, WebhookInboxEntry::query()->sole()->organization_id);

        // An Acme stock change is pushed to Acme's store only.
        $this->shopify->pushes = [];
        $betaShop->pushes = [];
        StockAdjustment::adjust($this->bolt->fresh(), -1, 'manual', 'test', null, null, true, $this->admin, $this->binA->id);

        $this->assertNotEmpty($this->shopify->pushes);
        $this->assertSame([], $betaShop->pushes);
        $this->assertSame(Connection::CONNECTED, $otherStore->fresh()->status);
    }
}
