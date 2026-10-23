<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use App\Mail\ShipmentShippedEmail;
use App\Models\Order\Order;
use App\Services\Shipping\ShipmentService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inventoros\Plugins\Shopify\Jobs\PushFulfilments;
use Inventoros\Plugins\Shopify\Kit\Exceptions\RateLimited;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Kit\Models\OutboxEntry;
use Inventoros\Plugins\Shopify\Support\Listeners;
use Inventoros\Plugins\Shopify\Sync\FulfilmentPush;
use Inventoros\Plugins\Shopify\Sync\OrderImporter;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\TestCase;

final class FulfilmentPushTest extends TestCase
{
    use ShopifyWorld;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FulfilmentPush::supported()) {
            $this->markTestSkipped('Requires core shipment notification ownership.');
        }
        $this->setUpWorld();
        $this->mapLocations();
        $this->mapCatalog();
        $this->store->update(['settings' => array_merge($this->store->settings, ['push_fulfilments' => true])]);
        Queue::fake();
        app(OrderImporter::class)->import($this->store, $this->shopify->orders[0]);
        $this->order = Order::withoutGlobalScopes()->where('organization_id', $this->organization->id)->sole();
        $line = $this->order->items()->where('product_id', $this->bolt->id)->sole();
        $this->shopify->fulfilmentOrderRows = [[
            'id' => '7001', 'location_id' => '1002', 'status' => 'OPEN', 'request_status' => 'UNSUBMITTED',
            'lines' => [['id' => '8001', 'line_id' => $this->order->metadata['shopify']['line_items'][$line->id], 'inventory_item_id' => '4001', 'remaining' => 2, 'stocked' => false]],
        ]];
        $this->shopify->calls = [];
    }

    private function ship(bool $notify = true)
    {
        $line = $this->order->items()->where('product_id', $this->bolt->id)->sole();
        $service = app(ShipmentService::class);
        $shipment = $service->create($this->order, [
            'carrier' => 'manual', 'carrier_name' => 'UPS', 'tracking_number' => 'FUL-TRACK', 'notify_customer' => $notify,
            'warehouse_id' => $this->main->id, 'items' => [['order_item_id' => $line->id, 'quantity' => 1]],
        ], $this->admin);
        $service->markShipped($shipment, $this->admin);

        return $shipment;
    }

    public function test_partial_shipment_activates_before_moving_and_lets_shopify_send_the_email_once(): void
    {
        $before = $this->bolt->fresh()->stock;
        $shipment = $this->ship();
        Queue::assertPushed(PushFulfilments::class);
        Mail::assertNotQueued(ShipmentShippedEmail::class);
        (new PushFulfilments($this->store->id))->handle();
        $this->assertCount(1, $this->shopify->fulfilments);
        $payload = $this->shopify->fulfilments[0];
        $this->assertSame(1, $payload['lineItemsByFulfillmentOrder'][0]['fulfillmentOrderLineItems'][0]['quantity']);
        $this->assertTrue($payload['notifyCustomer']);
        $this->assertSame('FUL-TRACK', $payload['trackingInfo']['number']);
        $calls = array_column($this->shopify->calls, 0);
        $this->assertLessThan(array_search('moveFulfilmentOrder', $calls), array_search('activateInventory', $calls));
        $this->assertSame($before, $this->bolt->fresh()->stock, 'Shipping does not deduct Inventoros stock again.');
        $this->assertNotNull($shipment->fresh()->customer_notification_queued_at);
        Listeners::shipmentShipped($shipment);
        (new PushFulfilments($this->store->id))->handle();
        $this->assertCount(1, $this->shopify->fulfilments);
    }

    public function test_existing_destination_stock_is_not_reactivated_and_notifications_can_be_off(): void
    {
        $this->shopify->fulfilmentOrderRows[0]['location_id'] = '1001';
        $this->shopify->fulfilmentOrderRows[0]['lines'][0]['stocked'] = true;
        $this->ship(false);
        (new PushFulfilments($this->store->id))->handle();
        $this->assertFalse($this->shopify->fulfilments[0]['notifyCustomer']);
        $this->assertSame(0, $this->shopify->callsTo('activateInventory'));
        $this->assertSame(0, $this->shopify->callsTo('moveFulfilmentOrder'));
    }

    public function test_historical_orders_are_excluded_from_fulfilment_push(): void
    {
        $metadata = $this->order->metadata;
        $metadata['shopify']['historical'] = true;
        $this->order->update(['metadata' => $metadata]);
        $shipment = $this->ship(false);
        $this->assertNull(FulfilmentPush::connectionFor($shipment));
        Queue::assertNotPushed(PushFulfilments::class);
        $this->assertSame(0, OutboxEntry::where('kind', FulfilmentPush::KIND)->count());
    }

    public function test_orders_that_never_committed_stock_are_excluded_from_fulfilment_push(): void
    {
        $this->order->forceFill(['stock_committed' => false])->save();
        $shipment = $this->ship(false);
        $this->assertNull(FulfilmentPush::connectionFor($shipment));
        Queue::assertNotPushed(PushFulfilments::class);
    }

    public function test_throttling_waits_then_retries_without_creating_duplicates(): void
    {
        $this->ship();
        $this->shopify->failNext('createFulfilment', new RateLimited('Slow down', 5));
        (new PushFulfilments($this->store->id))->handle();
        $this->assertCount(0, $this->shopify->fulfilments);
        (new PushFulfilments($this->store->id))->handle();
        $this->assertCount(1, $this->shopify->fulfilments);
        $this->assertSame(0, DeadLetter::count());
    }

    public function test_a_lost_creation_response_stops_for_review_and_never_blindly_replays(): void
    {
        $shipment = $this->ship();
        $this->shopify->loseFulfilmentResponse = true;
        (new PushFulfilments($this->store->id))->handle();
        $this->assertCount(1, $this->shopify->fulfilments);
        $this->assertSame('ambiguous', Mapping::where('entity', FulfilmentPush::KIND)->sole()->meta('state'));
        $this->assertSame(0, OutboxEntry::where('kind', FulfilmentPush::KIND)->count());
        $this->assertSame(1, DeadLetter::count());
        Listeners::shipmentShipped($shipment);
        (new PushFulfilments($this->store->id))->handle();
        $this->assertCount(1, $this->shopify->fulfilments);
    }

    public function test_paused_connections_keep_shipment_work_and_do_not_send_a_second_core_email(): void
    {
        $this->store->update(['paused_until' => now()->addHour()]);
        $this->ship();
        (new PushFulfilments($this->store->id))->handle();
        $this->assertSame(1, OutboxEntry::where('kind', FulfilmentPush::KIND)->count());
        Mail::assertNotQueued(ShipmentShippedEmail::class);
        $this->store->update(['paused_until' => null]);
        (new PushFulfilments($this->store->id))->handle();
        $this->assertCount(1, $this->shopify->fulfilments);
    }

    public function test_missing_line_mapping_or_insufficient_remote_quantity_cannot_fulfil_everything_accidentally(): void
    {
        $this->shopify->fulfilmentOrderRows[0]['lines'][0]['remaining'] = 0;
        $this->ship();
        (new PushFulfilments($this->store->id))->handle();
        $this->assertCount(0, $this->shopify->fulfilments);
        $this->assertSame(1, DeadLetter::count());
    }
}
