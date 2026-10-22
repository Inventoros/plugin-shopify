<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use App\Services\ProductLocationStockService;
use App\Services\StockReservationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inventoros\Plugins\Shopify\Jobs\PushInventory;
use Inventoros\Plugins\Shopify\Sync\InventoryPull;
use Inventoros\Plugins\Shopify\Sync\InventoryPush;
use Inventoros\Plugins\Shopify\Sync\InventoryStock;
use Inventoros\Plugins\Shopify\Sync\Locations;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\TestCase;

final class VariantWarehouseStockTest extends TestCase
{
    use ShopifyWorld;

    protected function setUp(): void
    {
        parent::setUp();
        if (! InventoryStock::supportsVariantBins() || ! class_exists(StockReservationService::class)) {
            $this->markTestSkipped('Requires core variant bins and reservations.');
        }
        $this->setUpWorld();
        $this->tee->update(['location_id' => $this->binA->id]);
        $this->teeS->refresh();
        DB::transaction(fn () => app(ProductLocationStockService::class)->ensureBinned($this->teeS));
        $this->mapLocations();
        $this->mapCatalog();
        Queue::fake();
    }

    private function quantities(): array
    {
        return array_column(app(InventoryPush::class)->quantitiesFor($this->store, 'v'.$this->teeS->id, app(Locations::class)->mapped($this->store), app(Locations::class)->primary($this->store->id)), 'quantity', 'location_id');
    }

    public function test_variant_moves_and_reservations_publish_per_warehouse_availability(): void
    {
        DB::transaction(fn () => app(ProductLocationStockService::class)->move($this->teeS, $this->binA->id, $this->annexBin->id, 2));
        $this->assertSame([1001 => 3, 1002 => 2], $this->quantities());
        app(StockReservationService::class)->reserve($this->tee, $this->teeS, 2, 'core', 'local', locationId: $this->annexBin->id);
        $this->assertSame([1001 => 3, 1002 => 0], $this->quantities());
        Queue::assertPushed(PushInventory::class);
        app(StockReservationService::class)->reserve($this->tee, $this->teeS, 2, 'core', 'global');
        $this->assertSame([1001 => 1, 1002 => 0], $this->quantities());
        app(StockReservationService::class)->release('core', 'local', $this->organization->id);
        $this->assertSame(3, array_sum($this->quantities()));
    }

    public function test_pulling_one_warehouse_does_not_overwrite_another_and_can_reduce_multiple_bins(): void
    {
        DB::transaction(fn () => app(ProductLocationStockService::class)->move($this->teeS, $this->binA->id, $this->annexBin->id, 2));
        $second = $this->bin('A-02', $this->main);
        DB::transaction(fn () => app(ProductLocationStockService::class)->move($this->teeS, $this->binA->id, $second->id, 1));
        $pull = app(InventoryPull::class);
        $this->assertSame('applied', $pull->apply($this->store, '4002', '1001', 0, null, null));
        $this->assertSame(2, $this->teeS->fresh()->stock);
        $this->assertSame(2, app(ProductLocationStockService::class)->quantityAt($this->teeS, $this->annexBin->id));
        $this->assertSame('applied', $pull->apply($this->store, '4002', '1002', 7, null, null));
        $this->assertSame(7, $this->teeS->fresh()->stock);
        $this->assertSame('unchanged', $pull->apply($this->store, '4002', '1002', 7, null, null));
    }
}
