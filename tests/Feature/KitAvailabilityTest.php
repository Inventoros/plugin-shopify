<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Services\StockReservationService;
use Illuminate\Support\Facades\Queue;
use Inventoros\Plugins\Shopify\Jobs\PushInventory;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Kit\Models\OutboxEntry;
use Inventoros\Plugins\Shopify\Sync\Catalog;
use Inventoros\Plugins\Shopify\Sync\KitStock;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\TestCase;

/**
 * On cores that sell kits from their components, a kit mapped to a Shopify
 * variant shows how many its components can make, follows its components'
 * stock and reservations, and a Shopify order for it takes the components.
 *
 * The recorded catalog's BOLT-1 variant (inventory item 4001) is mapped to
 * a kit of 2 x Part A (7 on hand) and 1 x Part B (20 on hand): 3 kits.
 */
final class KitAvailabilityTest extends TestCase
{
    use ShopifyWorld;

    private Product $partA;

    private Product $partB;

    private Product $kit;

    protected function setUp(): void
    {
        parent::setUp();

        if (! KitStock::supported()) {
            $this->markTestSkipped('This Inventoros release sells kits from their own stock.');
        }

        $this->setUpWorld();
        $this->mapLocations();
        $this->mapCatalog();

        $this->partA = $this->product('PART-A', 'Part A', 7, $this->binA);
        $this->partB = $this->product('PART-B', 'Part B', 20, $this->binA);
        $this->kit = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'KIT-1',
            'name' => 'Bolt kit',
            'type' => 'kit',
            'price' => 12,
            'currency' => 'USD',
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        ProductComponent::create(['parent_product_id' => $this->kit->id, 'component_product_id' => $this->partA->id, 'quantity' => 2, 'sort_order' => 0]);
        ProductComponent::create(['parent_product_id' => $this->kit->id, 'component_product_id' => $this->partB->id, 'quantity' => 1, 'sort_order' => 1]);

        Mapping::query()
            ->where('connection_id', $this->store->id)
            ->where('entity', Catalog::ENTITY)
            ->where('internal_type', 'product')
            ->where('internal_id', $this->bolt->id)
            ->update(['internal_id' => $this->kit->id]);

        $this->shopify->pushes = [];
        $this->shopify->calls = [];
    }

    /**
     * @return array<string, int> "item@location" => quantity
     */
    private function pushed(): array
    {
        $out = [];
        foreach ($this->shopify->pushedQuantities() as $row) {
            $out[$row['inventory_item_id'].'@'.$row['location_id']] = $row['quantity'];
        }

        return $out;
    }

    public function test_a_component_stock_change_pushes_how_many_kits_can_be_made_at_the_primary_location(): void
    {
        StockAdjustment::adjust($this->partA->fresh(), -1, 'manual', 'test', null, null, true, $this->admin, $this->binA->id);

        // 6 of Part A make 3 kits; the kit has no bins, so only the primary
        // location is set, never the other mapped warehouse.
        $this->assertSame(['4001@1001' => 3], $this->pushed());
    }

    public function test_reserving_a_component_lowers_the_pushed_kit_count(): void
    {
        app(StockReservationService::class)->reserve($this->partB->fresh(), null, 19, 'shop', 'CART-1');

        $this->assertSame(['4001@1001' => 1], $this->pushed());
    }

    public function test_a_change_to_an_unrelated_product_does_not_push_the_kit(): void
    {
        Queue::fake();
        $loose = $this->product('LOOSE-1', 'Loose part', 5, $this->binA);

        StockAdjustment::adjust($loose->fresh(), 1, 'manual', 'test', null, null, true, $this->admin, $this->binA->id);

        $this->assertSame(0, OutboxEntry::query()->count());
        Queue::assertNotPushed(PushInventory::class);
    }

    public function test_the_scheduled_sync_repushes_mapped_kits_so_a_component_list_edit_reaches_shopify(): void
    {
        Queue::fake();
        ProductComponent::query()->where('parent_product_id', $this->kit->id)->where('component_product_id', $this->partA->id)->update(['quantity' => 1]);

        $this->artisan('shopify:sync')->assertSuccessful();

        $this->assertSame(['p'.$this->kit->id], OutboxEntry::query()->pluck('subject')->all());
        Queue::assertPushed(PushInventory::class);

        (new PushInventory($this->store->id))->handle();

        $this->assertSame(['4001@1001' => 7], $this->pushed());
    }

    public function test_a_kit_core_cannot_sell_is_pushed_as_zero(): void
    {
        ProductComponent::query()->where('parent_product_id', $this->kit->id)->where('component_product_id', $this->partA->id)->update(['quantity' => 1.5]);

        KitStock::queueMapped($this->store);
        (new PushInventory($this->store->id))->handle();

        $this->assertSame(['4001@1001' => 0], $this->pushed());
    }

    public function test_a_shopify_order_for_the_kit_takes_its_components(): void
    {
        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk();

        $order = Order::withoutGlobalScopes()->where('organization_id', $this->organization->id)->where('source', 'shopify')->with('items')->sole();
        $this->assertSame(2, (int) $order->items->firstWhere('product_id', $this->kit->id)->quantity);
        $this->assertSame(3, $this->partA->fresh()->stock, '2 kits take 4 of Part A');
        $this->assertSame(18, $this->partB->fresh()->stock, '2 kits take 2 of Part B');
        $this->assertSame(0, $this->kit->fresh()->stock, 'the kit itself holds no stock');
    }

    public function test_a_shopify_order_for_more_kits_than_can_be_made_is_held_back_naming_the_component(): void
    {
        StockAdjustment::adjust($this->partA->fresh(), -6, 'manual', 'test', null, null, true, $this->admin, $this->binA->id);

        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk();

        $this->assertSame(0, Order::withoutGlobalScopes()->where('organization_id', $this->organization->id)->where('source', 'shopify')->count());
        $this->assertSame(1, $this->partA->fresh()->stock);
        $this->assertSame(20, $this->partB->fresh()->stock);
        $error = DeadLetter::query()->sole()->error;
        $this->assertStringContainsString('not enough stock', $error);
        $this->assertStringContainsString('Part A', $error);
    }

    public function test_a_shopify_level_for_the_kit_is_never_written_back_to_inventoros(): void
    {
        $this->store->forceFill(['settings' => array_merge($this->store->settings, ['stock_source' => 'shopify'])])->save();

        $this->webhook('inventory_levels/update', $this->payload('inventory_levels_update'))->assertOk();

        $this->assertSame(0, $this->kit->fresh()->stock);
        $this->assertSame(0, StockAdjustment::query()->where('product_id', $this->kit->id)->count());
    }
}
