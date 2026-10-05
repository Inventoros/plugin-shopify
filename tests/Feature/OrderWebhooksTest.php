<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Kit\Models\WebhookInboxEntry;
use Inventoros\Plugins\Shopify\Support\Core;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\TestCase;

/**
 * orders/create, orders/updated and orders/cancelled through the inbox into
 * core orders: exactly once, in any delivery order, with core stock
 * integrity (ledger rows, bins) and restock on cancellation.
 */
final class OrderWebhooksTest extends TestCase
{
    use ShopifyWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        $this->mapLocations();
        $this->mapCatalog();
    }

    private function orders()
    {
        return Order::withoutGlobalScopes()->where('organization_id', $this->organization->id)->where('source', 'shopify');
    }

    private function binQuantity(): int
    {
        return (int) ProductLocationStock::query()->where('product_id', $this->bolt->id)->where('location_id', $this->binA->id)->value('quantity');
    }

    public function test_an_order_is_imported_through_core_with_ledger_rows_and_bins(): void
    {
        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk();

        $order = $this->orders()->with('items')->sole();
        $this->assertSame('shopify:acme-store.myshopify.com:5001', $order->external_reference);
        $this->assertSame('5001', $order->external_id);
        $this->assertSame($this->main->id, $order->warehouse_id);
        $this->assertSame('Ada Lovelace', $order->customer_name);
        $this->assertCount(2, $order->items);
        $this->assertSame('1.50', (string) $order->items->firstWhere('product_variant_id', $this->teeS->id)->discount_amount);

        $this->assertSame(8, $this->bolt->fresh()->stock);
        $this->assertSame(8, $this->binQuantity(), 'the sold units come out of the bin');
        $this->assertSame(4, $this->teeS->fresh()->stock);

        $ledger = StockAdjustment::query()->where('reference_type', Order::class)->where('reference_id', $order->id)->get();
        $this->assertCount(2, $ledger);
        $this->assertSame([-2, -1], $ledger->sortBy('id')->pluck('adjustment_quantity')->map(fn ($q) => (int) $q)->values()->all());
        $this->assertSame('order_fulfillment', $ledger->first()->type);
        $this->assertSame($this->admin->id, $ledger->first()->user_id);

        $this->assertSame(WebhookInboxEntry::PROCESSED, WebhookInboxEntry::query()->sole()->status);
        $this->assertSame($order->id, $this->mapping('order', '5001')->internal_id);
    }

    public function test_a_redelivery_with_the_same_event_id_is_acknowledged_and_not_imported_again(): void
    {
        $headers = ['X-Shopify-Event-Id' => 'evt-same'];

        $this->webhook('orders/create', $this->payload('orders_create'), $headers)->assertOk();
        $this->webhook('orders/create', $this->payload('orders_create'), $headers)->assertOk()->assertJson(['message' => 'Already received.']);

        $this->assertSame(1, $this->orders()->count());
        $this->assertSame(8, $this->bolt->fresh()->stock);
        $this->assertSame(1, WebhookInboxEntry::query()->count());
    }

    public function test_a_replayed_body_with_a_new_event_id_is_deduplicated_by_its_hash(): void
    {
        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk();
        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk()->assertJson(['message' => 'Already received.']);

        $this->assertSame(1, $this->orders()->count());
        $this->assertSame(8, $this->bolt->fresh()->stock);
    }

    public function test_create_and_update_deliveries_of_one_order_import_it_exactly_once(): void
    {
        $updated = $this->payload('orders_create');
        $updated['updated_at'] = '2026-10-04T10:05:00Z';
        $updated['financial_status'] = 'paid';
        $updated['note'] = 'Gift wrap';

        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk();
        $this->webhook('orders/updated', $updated)->assertOk();
        $this->webhook('orders/updated', $updated + ['tags' => 'vip'])->assertOk();

        $this->assertSame(1, $this->orders()->count());
        $this->assertSame(8, $this->bolt->fresh()->stock);
        $this->assertSame(3, WebhookInboxEntry::query()->where('status', WebhookInboxEntry::PROCESSED)->count());
    }

    public function test_an_update_that_arrives_before_the_create_imports_once_and_the_late_create_is_a_no_op(): void
    {
        $updated = $this->payload('orders_create');
        $updated['updated_at'] = '2026-10-04T10:05:00Z';

        $this->webhook('orders/updated', $updated)->assertOk();
        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk();

        $this->assertSame(1, $this->orders()->count());
        $this->assertSame(8, $this->bolt->fresh()->stock);
        $this->assertSame(WebhookInboxEntry::IGNORED, WebhookInboxEntry::query()->where('topic', 'orders/create')->sole()->status, 'the older create is stale');
    }

    public function test_a_cancellation_that_arrives_before_the_create_means_the_order_is_never_imported(): void
    {
        $this->webhook('orders/cancelled', $this->payload('orders_cancelled'))->assertOk();
        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk();

        $this->assertSame(0, $this->orders()->count());
        $this->assertSame(10, $this->bolt->fresh()->stock);
        $this->assertSame(5, $this->teeS->fresh()->stock);
        $this->assertSame(Mapping::IGNORED, $this->mapping('order', '5001')->status);
    }

    public function test_a_cancellation_cancels_through_core_and_restocks_once(): void
    {
        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk();
        $this->assertSame(8, $this->bolt->fresh()->stock);

        $this->webhook('orders/cancelled', $this->payload('orders_cancelled'))->assertOk();
        // Shopify also sends orders/updated for the same change.
        $this->webhook('orders/updated', $this->payload('orders_cancelled'), ['X-Shopify-Event-Id' => 'evt-updated-copy'])->assertOk();
        $again = $this->payload('orders_cancelled');
        $again['updated_at'] = '2026-10-04T10:31:00Z';
        $this->webhook('orders/updated', $again)->assertOk();

        $order = $this->orders()->sole();
        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertSame(10, $this->bolt->fresh()->stock, 'restocked once');
        $this->assertSame(10, $this->binQuantity());
        $this->assertSame(5, $this->teeS->fresh()->stock);
    }

    public function test_a_cancellation_passes_the_connection_user_to_core_instead_of_signing_in(): void
    {
        $this->assertTrue(Core::cancelTakesActor(), 'OrderService::cancel() on this core takes an actor');

        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk();

        $signedInDuringCancel = [];
        add_action('order_updated', function ($order, $user = null) use (&$signedInDuringCancel): void {
            if ($order->status === OrderStatus::CANCELLED) {
                $signedInDuringCancel[] = $user?->id;
            }
        });

        $this->webhook('orders/cancelled', $this->payload('orders_cancelled'))->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $this->orders()->sole()->status);
        $this->assertSame([null], $signedInDuringCancel, 'nobody is signed in while core cancels the order');
        $this->assertNull(auth()->user());
        $this->assertSame(
            [$this->admin->id],
            StockAdjustment::withoutGlobalScopes()->where('adjustment_quantity', '>', 0)->pluck('user_id')->unique()->values()->all(),
            'the restock is booked as the connection user',
        );
    }

    public function test_an_older_update_after_the_cancellation_does_not_reopen_anything(): void
    {
        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk();
        $this->webhook('orders/cancelled', $this->payload('orders_cancelled'))->assertOk();

        $older = $this->payload('orders_create');
        $older['updated_at'] = '2026-10-04T10:10:00Z';
        $this->webhook('orders/updated', $older)->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $this->orders()->sole()->status);
        $this->assertSame(10, $this->bolt->fresh()->stock);
    }

    public function test_an_order_with_an_unmapped_sku_waits_in_the_error_list_and_imports_after_mapping(): void
    {
        $order = $this->payload('orders_create');
        $order['id'] = 5003;
        $order['name'] = '#1003';
        $order['line_items'] = [[
            'id' => 6004, 'variant_id' => 3005, 'product_id' => 2004, 'sku' => 'WASH-1', 'title' => 'Washer', 'name' => 'Washer',
            'quantity' => 10, 'price' => '0.50', 'total_discount' => '0.00',
        ]];

        $this->webhook('orders/create', $order)->assertOk();

        $this->assertSame(0, $this->orders()->count());
        $this->assertSame(WebhookInboxEntry::DEAD, WebhookInboxEntry::query()->sole()->status);
        $letter = DeadLetter::query()->sole();
        $this->assertStringContainsString('WASH-1', $letter->error);
        $this->assertStringContainsString('not mapped', $letter->error);

        $washer = $this->product('WASH-1', 'Washer', 30, $this->binA);
        $this->actingAs($this->admin)
            ->post(route('shopify.actions.mappings.link', $this->mapping('variant', '3005')), ['sku' => 'WASH-1'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('shopify.actions.failed.retry', $letter))->assertRedirect();

        $this->assertSame(1, $this->orders()->count());
        $this->assertSame(20, $washer->fresh()->stock);
        $this->assertNotNull($letter->fresh()->resolved_at);
        $this->assertSame(WebhookInboxEntry::PROCESSED, WebhookInboxEntry::query()->sole()->status);
    }

    public function test_an_order_inventoros_cannot_fill_is_not_imported_and_stock_is_untouched(): void
    {
        $order = $this->payload('orders_create');
        $order['line_items'][0]['quantity'] = 50;

        $this->webhook('orders/create', $order)->assertOk();

        $this->assertSame(0, $this->orders()->count());
        $this->assertSame(10, $this->bolt->fresh()->stock);
        $this->assertSame(5, $this->teeS->fresh()->stock);
        $this->assertStringContainsString('not enough stock', DeadLetter::query()->sole()->error);
    }

    public function test_test_orders_are_skipped_unless_enabled(): void
    {
        $order = $this->payload('orders_create');
        $order['test'] = true;

        $this->webhook('orders/create', $order)->assertOk();

        $this->assertSame(0, $this->orders()->count());
        $this->assertSame(10, $this->bolt->fresh()->stock);
    }
}
