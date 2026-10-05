<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockAdjustment;
use Inventoros\Plugins\Shopify\Jobs\RunSync;
use Inventoros\Plugins\Shopify\Kit\Models\WebhookInboxEntry;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\TestCase;

/**
 * When Shopify owns stock, its inventory levels become audited core
 * adjustments at the mapped warehouse; when Inventoros owns stock they are
 * recorded and left alone.
 */
final class InventoryPullTest extends TestCase
{
    use ShopifyWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        $this->store->forceFill(['settings' => array_merge($this->store->settings, ['stock_source' => 'shopify'])])->save();
        $this->mapLocations();
        $this->mapCatalog();
    }

    private function binQty(int $locationId): int
    {
        return (int) ProductLocationStock::query()->where('product_id', $this->bolt->id)->where('location_id', $locationId)->value('quantity');
    }

    public function test_a_level_update_becomes_one_audited_adjustment_in_the_mapped_warehouse(): void
    {
        $this->webhook('inventory_levels/update', $this->payload('inventory_levels_update'))->assertOk();

        $this->assertSame(7, $this->bolt->fresh()->stock);
        $this->assertSame(7, $this->binQty($this->binA->id));

        $adjustment = StockAdjustment::query()->where('product_id', $this->bolt->id)->sole();
        $this->assertSame('correction', $adjustment->type);
        $this->assertSame(-3, (int) $adjustment->adjustment_quantity);
        $this->assertSame($this->binA->id, $adjustment->location_id);
        $this->assertSame($this->admin->id, $adjustment->user_id);
        $this->assertSame('Shopify inventory sync', $adjustment->reason);
    }

    public function test_the_same_level_twice_changes_nothing_the_second_time(): void
    {
        $this->webhook('inventory_levels/update', $this->payload('inventory_levels_update'))->assertOk();
        $level = $this->payload('inventory_levels_update');
        $level['updated_at'] = '2026-10-04T14:01:00Z';
        $this->webhook('inventory_levels/update', $level)->assertOk();

        $this->assertSame(7, $this->bolt->fresh()->stock);
        $this->assertSame(1, StockAdjustment::query()->where('product_id', $this->bolt->id)->count());
    }

    public function test_an_older_level_that_arrives_late_is_ignored(): void
    {
        $this->webhook('inventory_levels/update', $this->payload('inventory_levels_update'))->assertOk();

        $older = $this->payload('inventory_levels_update');
        $older['available'] = 2;
        $older['updated_at'] = '2026-10-04T13:00:00Z';
        $this->webhook('inventory_levels/update', $older)->assertOk();

        $this->assertSame(7, $this->bolt->fresh()->stock);
        $this->assertSame(WebhookInboxEntry::IGNORED, WebhookInboxEntry::query()->latest('id')->first()->status);
    }

    public function test_the_other_location_adjusts_its_own_warehouse(): void
    {
        $level = $this->payload('inventory_levels_update');
        $level['location_id'] = 1002;
        $level['available'] = 4;
        $this->webhook('inventory_levels/update', $level)->assertOk();

        $this->assertSame(14, $this->bolt->fresh()->stock);
        $this->assertSame(10, $this->binQty($this->binA->id));
        $this->assertSame(4, $this->binQty($this->annexBin->id));
    }

    public function test_a_variant_level_at_the_primary_location_adjusts_the_variant(): void
    {
        $this->webhook('inventory_levels/update', ['inventory_item_id' => 4002, 'location_id' => 1001, 'available' => 9, 'updated_at' => '2026-10-04T14:00:00Z'])->assertOk();

        $this->assertSame(9, $this->teeS->fresh()->stock);
        $this->assertSame(4, (int) StockAdjustment::query()->where('product_variant_id', $this->teeS->id)->sole()->adjustment_quantity);
    }

    public function test_levels_are_ignored_while_inventoros_owns_stock(): void
    {
        $this->store->forceFill(['settings' => array_merge($this->store->settings, ['stock_source' => 'inventoros'])])->save();

        $this->webhook('inventory_levels/update', $this->payload('inventory_levels_update'))->assertOk();

        $this->assertSame(10, $this->bolt->fresh()->stock);
        $this->assertSame(0, StockAdjustment::query()->count());
        $this->assertSame(WebhookInboxEntry::IGNORED, WebhookInboxEntry::query()->sole()->status);
    }

    public function test_a_full_inventory_resync_reads_every_mapped_item(): void
    {
        RunSync::dispatch($this->store->id, ['inventory'], 'manual', 'user', $this->admin->id);

        $this->assertSame(28, $this->bolt->fresh()->stock, '25 at the main warehouse, 3 at the pop-up');
        $this->assertSame(25, $this->binQty($this->binA->id));
        $this->assertSame(3, $this->binQty($this->annexBin->id));
        $this->assertSame(9, $this->teeS->fresh()->stock);
        $this->assertSame(0, $this->shopify->callsTo('setInventoryQuantities'), 'pulling never pushes back');
    }
}
