<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use App\Models\Order\Order;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Inventoros\Plugins\Shopify\Jobs\ImportOrder;
use Inventoros\Plugins\Shopify\Jobs\ProcessWebhook;
use Inventoros\Plugins\Shopify\Jobs\RunSync;
use Inventoros\Plugins\Shopify\Kit\Models\Cursor;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Kit\Models\SyncRun;
use Inventoros\Plugins\Shopify\Kit\Models\WebhookInboxEntry;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\TestCase;

/**
 * Initial, manual and incremental syncs: history per resource, cursors,
 * and partial failures that leave the rest of the run intact.
 */
final class SyncRunTest extends TestCase
{
    use ShopifyWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    private function shopifyOrders()
    {
        return Order::withoutGlobalScopes()->where('organization_id', $this->organization->id)->where('source', 'shopify');
    }

    public function test_the_initial_sync_records_one_run_per_resource_in_order(): void
    {
        $this->actingAs($this->admin)
            ->post(route('plg.shopify.actions.connections.sync', $this->store), ['resource' => 'all', 'mode' => 'initial'])
            ->assertRedirect();

        $runs = SyncRun::query()->where('type', 'initial')->orderBy('id')->get();
        $this->assertSame(['locations', 'products', 'inventory', 'orders'], $runs->pluck('resource')->all());
        $this->assertSame($this->admin->id, $runs->first()->user_id);
        $this->assertSame('user', $runs->first()->trigger);
        $this->assertSame(2, Mapping::query()->where('entity', 'location')->count());
        $this->assertSame(SyncRun::SUCCEEDED, $runs->firstWhere('resource', 'products')->status);
    }

    public function test_a_store_with_one_location_and_one_warehouse_is_mapped_without_asking(): void
    {
        $this->annex->update(['is_active' => false]);
        $this->shopify->locations = [['id' => '1001', 'name' => 'Main warehouse', 'active' => true]];

        RunSync::dispatch($this->store->id, ['locations'], 'initial', 'user', $this->admin->id);

        $location = $this->mapping('location', '1001');
        $this->assertSame(Mapping::MAPPED, $location->status);
        $this->assertSame($this->main->id, $location->internal_id);
        $this->assertTrue((bool) $location->meta('primary'));
    }

    public function test_an_order_sync_with_one_bad_order_is_partial_and_the_bad_one_can_be_retried(): void
    {
        $this->mapLocations();
        $this->mapCatalog();

        RunSync::dispatch($this->store->id, ['orders'], 'initial', 'user', $this->admin->id);

        $run = SyncRun::query()->where('resource', 'orders')->sole();
        $this->assertSame(SyncRun::PARTIAL, $run->status);
        $this->assertSame(3, $run->processed);
        $this->assertSame(2, $run->created);
        $this->assertSame(1, $run->failed);
        $this->assertSame(2, $this->shopifyOrders()->count());
        $this->assertSame(7, $this->bolt->fresh()->stock, '2 + 1 sold');

        $letter = DeadLetter::query()->sole();
        $this->assertSame('import_order', $letter->kind);
        $this->assertSame('order #1003', $letter->reference);

        $washer = $this->product('WASH-1', 'Washer', 12, $this->binA);
        app(\Inventoros\Plugins\Shopify\Kit\Mapping\MappingStore::class)->link($this->mapping('variant', '3005'), 'product', $washer->id);

        $this->actingAs($this->admin)->post(route('plg.shopify.actions.failed.retry', $letter))->assertRedirect();

        $this->assertSame(3, $this->shopifyOrders()->count());
        $this->assertSame(2, $washer->fresh()->stock);
    }

    public function test_running_the_same_order_sync_twice_imports_nothing_twice(): void
    {
        $this->mapLocations();
        $this->mapCatalog();

        RunSync::dispatch($this->store->id, ['orders'], 'manual', 'user', $this->admin->id);
        RunSync::dispatch($this->store->id, ['orders'], 'manual', 'user', $this->admin->id);
        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk();

        $this->assertSame(2, $this->shopifyOrders()->count());
        $this->assertSame(7, $this->bolt->fresh()->stock);
    }

    public function test_incremental_sync_reads_from_the_cursor_with_a_small_overlap(): void
    {
        $this->mapLocations();
        $this->mapCatalog();

        RunSync::dispatch($this->store->id, ['orders'], 'incremental', 'schedule');
        $position = Cursor::query()->where('resource', 'orders')->value('position_at');
        $this->assertSame('2026-10-04 12:00:01', \Illuminate\Support\Carbon::parse($position)->utc()->format('Y-m-d H:i:s'), 'the newest change seen');

        $this->shopify->calls = [];
        RunSync::dispatch($this->store->id, ['orders'], 'incremental', 'schedule');

        $call = collect($this->shopify->calls)->firstWhere(0, 'orders');
        $this->assertSame('2026-10-04T11:58:01+00:00', $call[1][1], 'updated since the cursor minus two minutes');
        $this->assertNull($call[1][2]);
    }

    public function test_orders_placed_before_the_start_date_are_not_imported(): void
    {
        $this->mapLocations();
        $this->mapCatalog();
        $this->store->forceFill(['settings' => array_merge($this->store->settings, ['orders_since' => '2026-10-05'])])->save();

        RunSync::dispatch($this->store->id, ['orders'], 'initial', 'user', $this->admin->id);

        $this->assertSame(0, $this->shopifyOrders()->count());
    }

    public function test_the_scheduled_command_queues_incremental_syncs_and_picks_up_waiting_webhooks(): void
    {
        Queue::fake();
        $entry = WebhookInboxEntry::create([
            'organization_id' => $this->organization->id,
            'connection_id' => $this->store->id,
            'topic' => 'orders/create',
            'event_id' => 'stuck-1',
            'payload_hash' => hash('sha256', 'x'),
            'payload' => '{}',
            'status' => WebhookInboxEntry::RECEIVED,
        ]);
        WebhookInboxEntry::query()->whereKey($entry->id)->update(['updated_at' => now()->subMinutes(10)]);

        Artisan::call('shopify:sync');

        Queue::assertPushed(RunSync::class, fn (RunSync $job) => $job->mode === 'incremental' && $job->resources === ['products', 'orders']);
        Queue::assertPushed(ProcessWebhook::class, fn (ProcessWebhook $job) => $job->entry === $entry->id);
    }

    public function test_the_scheduled_command_leaves_paused_and_failed_connections_alone(): void
    {
        Queue::fake();
        $this->store->forceFill(['paused_until' => now()->addMinutes(10)])->save();
        $this->connect([], null, null, 'second-store.myshopify.com')->forceFill(['status' => 'error'])->save();

        Artisan::call('shopify:sync');

        Queue::assertNotPushed(RunSync::class);
    }

    public function test_manual_resync_of_one_order_fetches_and_imports_just_that_order(): void
    {
        Queue::fake([ImportOrder::class]);
        $this->mapLocations();
        $this->mapCatalog();
        $mapping = app(\Inventoros\Plugins\Shopify\Kit\Mapping\MappingStore::class)->remember($this->store, 'order', '5002', ['external_label' => '#1002']);

        $this->actingAs($this->admin)->post(route('plg.shopify.actions.mappings.resync', $mapping))->assertRedirect();

        Queue::assertPushed(ImportOrder::class, fn (ImportOrder $job) => $job->orderId === '5002');
    }
}
