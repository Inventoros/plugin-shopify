<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Order\Order;
use App\Services\OrderPaymentService;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Sync\OrderImporter;
use Inventoros\Plugins\Shopify\Sync\Payments;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\TestCase;

final class PaymentRecordsTest extends TestCase
{
    use ShopifyWorld;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Payments::supported()) {
            $this->markTestSkipped('This core does not support imported-payment records. The feature remains disabled.');
        }
        $this->setUpWorld();
        $this->mapLocations();
        $this->mapCatalog();
        $this->store->forceFill(['settings' => array_replace($this->store->settings, ['import_payments' => true])])->save();
    }

    private function transaction(string $id, string $kind = 'capture', string $amount = '10.00', array $overrides = []): array
    {
        return array_replace(['id' => $id, 'kind' => $kind, 'status' => 'success', 'amount' => $amount,
            'currency' => 'USD', 'processed_at' => '2026-10-02T10:00:00Z', 'gateway' => 'example', 'parent_id' => null], $overrides);
    }

    private function coreOrder(): Order
    {
        return Order::withoutGlobalScopes()->where('organization_id', $this->store->organization_id)->where('external_id', '5001')->sole();
    }

    public function test_successful_receipts_and_refunds_are_recorded_once_and_authorizations_are_ignored(): void
    {
        $announcedBalances = [];
        add_action('order_created', function ($order) use (&$announcedBalances) {
            $announcedBalances[] = $order->amount_paid;
        });
        $this->shopify->transactions['5001'] = [
            $this->transaction('2', 'refund', '2.00'), $this->transaction('1'),
            $this->transaction('3', 'authorization', '100'), $this->transaction('4', overrides: ['status' => 'failure']),
        ];
        $importer = app(OrderImporter::class);
        $this->assertSame('created', $importer->import($this->store, $this->shopify->orders[0]));
        $core = $this->coreOrder();
        $this->assertSame('8.00', $core->amount_paid);
        $this->assertCount(2, $core->payments);
        $this->assertSame(['payment', 'refund'], $core->payments->map(fn ($row) => $row->type->value)->all());
        $this->assertSame('shopify:acme-store.myshopify.com:1', $core->payments->first()->reference);
        $this->assertSame('unchanged', $importer->import($this->store, $this->shopify->orders[0]));
        $this->assertSame(2, $core->payments()->count());
        $this->assertSame(8, $this->bolt->fresh()->stock);
        $this->assertSame(['8.00'], $announcedBalances);
    }

    public function test_historical_cancelled_receipts_and_refunds_are_silent_and_do_not_move_stock(): void
    {
        $this->store->forceFill(['settings' => array_replace($this->store->settings, ['orders_since' => '2026-01-01', 'history_before' => '2026-10-10'])])->save();
        $announced = 0;
        add_action('payment_recorded', function () use (&$announced) {
            $announced++;
        });
        $this->shopify->transactions['5001'] = [$this->transaction('1'), $this->transaction('2', 'refund', '10')];
        $order = array_replace($this->shopify->orders[0], ['cancelled_at' => '2026-10-03T10:00:00Z']);
        app(OrderImporter::class)->import($this->store, $order);
        $core = $this->coreOrder();
        $this->assertSame('cancelled', $core->status->value);
        $this->assertSame(PaymentStatus::REFUNDED, $core->payment_status);
        $this->assertSame('0.00', $core->amount_paid);
        $this->assertFalse($core->stock_committed);
        $this->assertSame(10, $this->bolt->fresh()->stock);
        $this->assertSame(0, $announced);
    }

    public function test_manual_payments_require_reconciliation_before_remote_records_are_added(): void
    {
        $this->store->forceFill(['settings' => array_replace($this->store->settings, ['import_payments' => false])])->save();
        app(OrderImporter::class)->import($this->store, $this->shopify->orders[0]);
        $core = $this->coreOrder();
        app(OrderPaymentService::class)->record($core, $this->admin, ['amount' => '2', 'method' => 'cash']);
        $this->store->forceFill(['settings' => array_replace($this->store->settings, ['import_payments' => true])])->save();
        $this->shopify->transactions['5001'] = [$this->transaction('1')];
        try {
            app(OrderImporter::class)->import($this->store, $this->shopify->orders[0]);
            $this->fail('Expected manual payment reconciliation.');
        } catch (PermanentError $error) {
            $this->assertStringContainsString('manually recorded', $error->getMessage());
        }
        $this->assertSame(1, $core->payments()->count());
        $this->assertSame('2.00', $core->fresh()->amount_paid);
    }

    public function test_a_manually_voided_import_is_not_resurrected_and_actual_overpayments_are_preserved(): void
    {
        $this->shopify->transactions['5001'] = [$this->transaction('1', amount: '100')];
        $importer = app(OrderImporter::class);
        $importer->import($this->store, $this->shopify->orders[0]);
        $core = $this->coreOrder();
        $this->assertSame(PaymentStatus::OVERPAID, $core->payment_status);
        app(OrderPaymentService::class)->void($core->payments()->first(), $this->admin, 'Manual correction');
        $importer->import($this->store, $this->shopify->orders[0]);
        $this->assertSame('0.00', $core->fresh()->amount_paid);
        $this->assertSame(1, $core->payments()->count());
    }

    public function test_currency_mismatches_and_excess_refunds_refuse_partial_ledgers(): void
    {
        $announced = 0;
        add_action('order_created', function () use (&$announced) {
            $announced++;
        });
        $this->shopify->transactions['5001'] = [$this->transaction('1'), $this->transaction('2', overrides: ['currency' => 'EUR'])];
        try {
            app(OrderImporter::class)->import($this->store, $this->shopify->orders[0]);
            $this->fail('Expected currency refusal.');
        } catch (PermanentError $error) {
            $this->assertStringContainsString('currency', $error->getMessage());
        }
        $this->assertSame(0, Order::withoutGlobalScopes()->where('external_id', '5001')->count());
        $this->shopify->transactions['5001'] = [$this->transaction('1'), $this->transaction('2', 'refund', '11')];
        try {
            app(OrderImporter::class)->import($this->store, $this->shopify->orders[0]);
            $this->fail('Expected excess refund refusal.');
        } catch (PermanentError $error) {
            $this->assertStringContainsString('refund', $error->getMessage());
        }
        $this->assertSame(0, Order::withoutGlobalScopes()->where('external_id', '5001')->count());
        $this->assertSame(10, $this->bolt->fresh()->stock);
        $this->assertSame(0, $announced);
    }

    public function test_refund_webhooks_refresh_the_order_and_record_the_new_refund(): void
    {
        $this->shopify->transactions['5001'] = [$this->transaction('1')];
        app(OrderImporter::class)->import($this->store, $this->shopify->orders[0]);
        $this->shopify->transactions['5001'][] = $this->transaction('2', 'refund', '3');
        $this->webhook('refunds/create', ['id' => 42, 'order_id' => 5001])->assertOk();
        $this->assertSame('7.00', $this->coreOrder()->amount_paid);
        $this->assertSame(2, $this->coreOrder()->payments()->count());
        $this->assertSame(8, $this->bolt->fresh()->stock);
    }

    public function test_disabled_payment_imports_make_no_transaction_requests(): void
    {
        $this->store->forceFill(['settings' => array_replace($this->store->settings, ['import_payments' => false])])->save();
        app(OrderImporter::class)->import($this->store, $this->shopify->orders[0]);
        $this->assertSame(0, $this->shopify->callsTo('transactions'));
        $this->assertSame(0, $this->coreOrder()->payments()->count());
    }

    public function test_a_receipt_recorded_after_local_cancellation_keeps_its_actual_amount_without_restocking(): void
    {
        $this->store->forceFill(['settings' => array_replace($this->store->settings, ['import_payments' => false])])->save();
        $importer = app(OrderImporter::class);
        $importer->import($this->store, $this->shopify->orders[0]);
        $cancelled = array_replace($this->shopify->orders[0], ['cancelled_at' => '2026-10-05T10:00:00Z', 'updated_at' => '2026-10-05T10:00:00Z']);
        $importer->import($this->store, $cancelled);
        $this->store->forceFill(['settings' => array_replace($this->store->settings, ['import_payments' => true])])->save();
        $this->shopify->transactions['5001'] = [$this->transaction('1')];
        $importer->import($this->store, $cancelled);
        $this->assertSame('10.00', $this->coreOrder()->amount_paid);
        $this->assertSame('cancelled', $this->coreOrder()->status->value);
        $this->assertSame(10, $this->bolt->fresh()->stock);
    }
}
