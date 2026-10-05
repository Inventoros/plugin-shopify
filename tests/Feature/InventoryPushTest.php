<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use App\Models\Inventory\StockAdjustment;
use Illuminate\Support\Facades\Queue;
use Inventoros\Plugins\Shopify\Jobs\PushInventory;
use Inventoros\Plugins\Shopify\Kit\Exceptions\TransientError;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use Inventoros\Plugins\Shopify\Kit\Models\OutboxEntry;
use Inventoros\Plugins\Shopify\Kit\Models\SyncRun;
use Inventoros\Plugins\Shopify\Kit\Outbox\Outbox;
use Inventoros\Plugins\Shopify\Sync\InventoryPush;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\TestCase;

/**
 * Inventoros stock changes reach Shopify as coalesced, queued, absolute
 * quantities per mapped location, without ever blocking the change itself.
 */
final class InventoryPushTest extends TestCase
{
    use ShopifyWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        $this->mapLocations();
        $this->mapCatalog();
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

    public function test_many_changes_to_one_item_coalesce_into_one_queued_push_of_the_final_quantity(): void
    {
        Queue::fake();

        foreach ([-1, -2, 3, -4, -1] as $delta) {
            StockAdjustment::adjust($this->bolt->fresh(), $delta, 'manual', 'test', null, null, true, $this->admin, $this->binA->id);
        }

        $this->assertSame(5, $this->bolt->fresh()->stock);
        $row = OutboxEntry::query()->sole();
        $this->assertSame('p'.$this->bolt->id, $row->subject);
        $this->assertSame(4, $row->coalesced, 'five changes, one row');
        Queue::assertPushed(PushInventory::class, 1);
        $this->assertSame(0, $this->shopify->callsTo('setInventoryQuantities'), 'nothing is sent inside the request');

        (new PushInventory($this->store->id))->handle();

        $this->assertSame(1, $this->shopify->callsTo('setInventoryQuantities'));
        $this->assertSame(['4001@1001' => 5, '4001@1002' => 0], $this->pushed());
        $this->assertSame(0, OutboxEntry::query()->count());
        $this->assertSame(SyncRun::SUCCEEDED, SyncRun::query()->where('type', 'push')->sole()->status);
    }

    public function test_a_variant_is_pushed_to_the_primary_location_only(): void
    {
        StockAdjustment::adjustVariant($this->teeS->fresh(), 2, 'manual', 'test', null, null, true, $this->admin);

        $this->assertSame(['4002@1001' => 7], $this->pushed());
    }

    public function test_a_product_binned_in_both_warehouses_pushes_each_warehouse_quantity(): void
    {
        StockAdjustment::adjust($this->bolt->fresh(), 6, 'manual', 'test', null, null, true, $this->admin, $this->annexBin->id);

        $this->assertSame(['4001@1001' => 10, '4001@1002' => 6], $this->pushed());
    }

    public function test_unmapped_items_and_suggestions_are_never_pushed(): void
    {
        $washer = $this->product('WASH-1', 'Washer', 3, $this->binA);
        StockAdjustment::adjust($washer, 1, 'manual', 'test', null, null, true, $this->admin, $this->binA->id);

        $this->assertSame(0, $this->shopify->callsTo('setInventoryQuantities'));
        $this->assertSame(0, OutboxEntry::query()->count());
    }

    public function test_nothing_is_pushed_when_shopify_owns_stock(): void
    {
        $this->store->forceFill(['settings' => array_merge($this->store->settings, ['stock_source' => 'shopify'])])->save();

        StockAdjustment::adjust($this->bolt->fresh(), 1, 'manual', 'test', null, null, true, $this->admin, $this->binA->id);

        $this->assertSame(0, $this->shopify->callsTo('setInventoryQuantities'));
    }

    public function test_a_shopify_outage_never_fails_the_inventoros_change_and_nothing_is_lost(): void
    {
        $this->shopify->failNext('setInventoryQuantities', ...array_fill(0, 5, new TransientError('Shopify had a server error (HTTP 503).')));

        $adjustment = StockAdjustment::adjust($this->bolt->fresh(), -3, 'manual', 'test', null, null, true, $this->admin, $this->binA->id);

        $this->assertNotNull($adjustment->id);
        $this->assertSame(7, $this->bolt->fresh()->stock);
        $this->assertSame(5, $this->shopify->callsTo('setInventoryQuantities'), 'retried, then given up');
        $letter = DeadLetter::query()->sole();
        $this->assertSame('push_inventory', $letter->kind);
        $this->assertSame(1, OutboxEntry::query()->count(), 'the item still waits in the outbox');

        // Shopify is back: retrying from the error list pushes the current quantity.
        $this->store->refresh()->forceFill(['paused_until' => null])->save();
        $this->actingAs($this->admin)->post(route('shopify.actions.failed.retry', $letter))->assertRedirect();

        $this->assertSame(['4001@1001' => 7, '4001@1002' => 0], array_slice($this->pushed(), 0, 2, true));
        $this->assertSame(0, OutboxEntry::query()->count());
    }

    public function test_a_row_changed_after_it_was_read_is_kept_for_the_next_push(): void
    {
        $outbox = app(Outbox::class);
        $read = $outbox->put($this->store, InventoryPush::KIND, 'p'.$this->bolt->id);
        $outbox->put($this->store, InventoryPush::KIND, 'p'.$this->bolt->id);

        $this->assertFalse($outbox->done($read), 'changed since it was read');
        $this->assertSame(1, OutboxEntry::query()->count());

        $this->assertTrue($outbox->done(OutboxEntry::query()->sole()));
    }

    public function test_a_push_uses_the_same_idempotency_key_on_retry_and_a_new_one_later(): void
    {
        $this->shopify->failNext('setInventoryQuantities', new TransientError('timeout'));

        StockAdjustment::adjust($this->bolt->fresh(), -1, 'manual', 'test', null, null, true, $this->admin, $this->binA->id);

        $keys = array_column(array_filter($this->shopify->calls, fn ($c) => $c[0] === 'setInventoryQuantities'), 1);
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0][1], $keys[1][1], 'a retry of the same push reuses its key');

        StockAdjustment::adjust($this->bolt->fresh(), 1, 'manual', 'test', null, null, true, $this->admin, $this->binA->id);
        StockAdjustment::adjust($this->bolt->fresh(), -1, 'manual', 'test', null, null, true, $this->admin, $this->binA->id);

        $later = array_column(array_filter($this->shopify->calls, fn ($c) => $c[0] === 'setInventoryQuantities'), 1);
        $this->assertNotSame($keys[0][1], end($later)[1], 'a later push of the same quantity is a new key');
    }
}
