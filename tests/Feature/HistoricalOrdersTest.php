<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Shopify\ShopifyConnectionTester;
use Inventoros\Plugins\Shopify\Sync\OrderImporter;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\TestCase;

final class HistoricalOrdersTest extends TestCase
{
    use ShopifyWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        $this->mapLocations();
        $this->mapCatalog();
        $this->store->forceFill(['settings' => array_replace($this->store->settings, ['orders_since' => '2026-08-01', 'history_before' => '2026-10-01'])])->save();
    }

    private function oldOrder(array $overrides = []): array
    {
        return array_replace($this->shopify->orders[0], ['created_at' => '2026-09-01T12:00:00Z', 'updated_at' => '2026-09-02T12:00:00Z'], $overrides);
    }

    public function test_old_sales_are_imported_once_without_stock_ledger_or_new_order_hooks(): void
    {
        $announced = 0;
        add_action('order_created', function () use (&$announced) {
            $announced++;
        });
        $ledger = StockAdjustment::count();
        $importer = app(OrderImporter::class);
        $this->bolt->forceFill(['stock' => 0])->save();
        $this->assertSame('created', $importer->import($this->store, $this->oldOrder(['fulfillment_status' => 'fulfilled'])));
        $core = Order::withoutGlobalScopes()->where('external_id', '5001')->sole();
        $this->assertFalse($core->stock_committed);
        $this->assertSame('not_required', $core->approval_status->value);
        $this->assertTrue($core->metadata['shopify']['historical']);
        $this->assertSame(OrderStatus::SHIPPED, $core->status);
        $this->assertSame(0, $this->bolt->fresh()->stock);
        $this->assertSame(5, $this->teeS->fresh()->stock);
        $this->assertSame($ledger, StockAdjustment::count());
        $this->assertSame(0, $announced);
        $this->assertSame('unchanged', $importer->import($this->store, $this->oldOrder(['fulfillment_status' => 'fulfilled'])));
        $this->assertSame(1, Order::withoutGlobalScopes()->where('external_id', '5001')->count());
    }

    public function test_historical_cancellations_never_restock_or_become_live_after_settings_change(): void
    {
        $importer = app(OrderImporter::class);
        $importer->import($this->store, $this->oldOrder());
        $this->store->forceFill(['settings' => ['import_orders' => true]])->save();
        $cancelled = $this->oldOrder(['updated_at' => '2026-10-02T12:00:00Z', 'cancelled_at' => '2026-10-02T12:00:00Z']);
        $this->assertSame('unchanged', $importer->import($this->store, $cancelled));
        $core = Order::withoutGlobalScopes()->where('external_id', '5001')->sole();
        $this->assertSame(OrderStatus::CANCELLED, $core->status);
        $this->assertFalse($core->stock_committed);
        $this->assertSame(10, $this->bolt->fresh()->stock);
        $this->assertSame(5, $this->teeS->fresh()->stock);
        $this->assertSame('stale', $importer->import($this->store, $this->oldOrder()));
        $this->assertSame(OrderStatus::CANCELLED, $core->fresh()->status);
    }

    public function test_already_cancelled_history_is_recorded_but_recent_orders_still_take_stock(): void
    {
        $importer = app(OrderImporter::class);
        $this->assertSame('created', $importer->import($this->store, $this->oldOrder(['cancelled_at' => '2026-09-02T12:00:00Z'])));
        $this->assertSame(OrderStatus::CANCELLED, Order::withoutGlobalScopes()->where('external_id', '5001')->sole()->status);
        $recent = $this->oldOrder(['id' => '9001', 'created_at' => '2026-10-02T00:00:00Z', 'updated_at' => '2026-10-02T00:00:00Z']);
        $this->assertSame('created', $importer->import($this->store, $recent));
        $this->assertTrue(Order::withoutGlobalScopes()->where('external_id', '9001')->sole()->stock_committed);
        $this->assertSame(8, $this->bolt->fresh()->stock);
    }

    public function test_only_records_ignored_by_the_start_date_can_be_revisited_as_history(): void
    {
        $importer = app(OrderImporter::class);
        $this->store->forceFill(['settings' => ['orders_since' => '2026-10-01']])->save();
        $this->assertSame('skipped', $importer->import($this->store, $this->oldOrder()));
        $this->assertTrue($this->mapping('order', '5001')->meta('ignored_before_start'));
        $this->store->forceFill(['settings' => ['orders_since' => '2026-08-01', 'history_before' => '2026-10-01']])->save();
        $this->assertSame('created', $importer->import($this->store, $this->oldOrder()));
        $this->assertSame(10, $this->bolt->fresh()->stock);
        Mapping::create(['organization_id' => $this->store->organization_id, 'connection_id' => $this->store->id,
            'entity' => 'order', 'external_id' => '9002', 'status' => Mapping::IGNORED]);
        $this->assertSame('skipped', $importer->import($this->store, $this->oldOrder(['id' => '9002'])));
    }

    public function test_connection_tests_identify_the_older_order_access_scope(): void
    {
        $this->shopify->shop['scopes'] = array_values(array_diff($this->shopify->shop['scopes'], ['read_all_orders']));
        $result = app(ShopifyConnectionTester::class)->test($this->store);
        $this->assertFalse($result->ok);
        $this->assertStringContainsString('read_all_orders', $result->message);
    }
}
