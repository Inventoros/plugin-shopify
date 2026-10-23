<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Sync;

use App\Models\Order\Order;
use App\Models\Order\OrderPayment;
use App\Services\OrderPaymentService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Kit\Mapping\MappingStore;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Shopify\ClientFactory;
use Inventoros\Plugins\Shopify\Shopify\SyncPolicy;
use Inventoros\Plugins\Shopify\Support\Core;

/** One-way records of money already processed by Shopify; never sends money. */
final class Payments
{
    public const ENTITY = 'payment_transaction';

    public static function supported(): bool
    {
        return method_exists(OrderPaymentService::class, 'recordImported');
    }

    public function read(Connection $connection, string $orderId): ?array
    {
        $policy = SyncPolicy::for($connection);
        if (! $policy->importsPayments() || ! $policy->importsOrders()) {
            return null;
        }
        if (! self::supported()) {
            throw new PermanentError('Update Inventoros to a core with imported-payment support before enabling Shopify payment imports.');
        }

        return app(ClientFactory::class)->make($connection)->transactions($orderId);
    }

    public function sync(Connection $connection, Order $order, ?array $transactions): void
    {
        if ($transactions === null || $transactions === []) {
            return;
        }
        $movements = [];
        foreach ($transactions as $transaction) {
            if (($transaction['status'] ?? '') !== 'success' || ! in_array($transaction['kind'] ?? '', ['sale', 'capture', 'refund'], true)) {
                continue; // Authorizations, voided authorizations and failures do not move money.
            }
            if (! preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/', (string) ($transaction['amount'] ?? ''))
                || ($transaction['id'] ?? '') === '' || empty($transaction['processed_at'])) {
                throw new PermanentError('Shopify returned an invalid transaction amount, ID or date. Refusing to guess a payment.');
            }
            if (($transaction['currency'] ?? '') !== $order->currency) {
                throw new PermanentError('Shopify transaction currency differs from the Inventoros order. Reconcile it before retrying; no currency conversion is assumed.');
            }
            if (! Money::isZero($transaction['amount'])) {
                $movements[] = $transaction;
            }
        }
        if ($movements === []) {
            return;
        }
        // Receipts precede refunds even when the provider returns them reversed.
        usort($movements, fn ($a, $b) => ($a['kind'] === 'refund') <=> ($b['kind'] === 'refund'));

        try {
            DB::transaction(function () use ($connection, $order, $movements) {
                $locked = Core::orders((int) $connection->organization_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $mapped = Mapping::forOrganization($connection->organization_id)->where('connection_id', $connection->id)
                    ->where('entity', self::ENTITY)->where('internal_type', 'order_payment')->whereNotNull('internal_id')->pluck('internal_id');
                if ($locked->payments()->withoutGlobalScopes()->active()->whereNotIn('id', $mapped)->exists()) {
                    throw new PermanentError('This order already has manually recorded or unrelated payments. Reconcile or void duplicate manual entries before retrying Shopify payment imports.');
                }
                $mappings = app(MappingStore::class);
                $service = app(OrderPaymentService::class);
                $actor = Core::actor($connection);
                foreach ($movements as $transaction) {
                    $mapping = $mappings->remember($connection, self::ENTITY, $transaction['id'], [
                        'external_label' => 'Transaction '.$transaction['id'],
                        'meta' => ['order_id' => $locked->id, 'kind' => $transaction['kind'], 'parent_id' => $transaction['parent_id'] ?? null],
                    ]);
                    $type = $transaction['kind'] === 'refund' ? 'refund' : 'payment';
                    if ($mapping->internal_id !== null) {
                        $payment = OrderPayment::withoutGlobalScopes()->where('organization_id', $connection->organization_id)->find($mapping->internal_id);
                        if ($payment === null || $payment->order_id !== $locked->id || $payment->type->value !== $type
                            || Money::compare($payment->amount, $transaction['amount']) !== 0) {
                            throw new PermanentError('A previously imported Shopify transaction changed or its payment is missing. Review the existing ledger before retrying.');
                        }

                        continue; // A manual void stays voided; never resurrect it.
                    }
                    $data = [
                        'source' => 'shopify', 'type' => $type,
                        'amount' => $transaction['amount'], 'method' => 'other',
                        'reference' => 'shopify:'.$connection->shop_domain.':'.$transaction['id'],
                        'paid_at' => $transaction['processed_at'],
                        'notes' => 'Imported Shopify '.$transaction['kind'].'; gateway: '.mb_substr((string) ($transaction['gateway'] ?? ''), 0, 120),
                    ];
                    $payment = $service->recordImported($locked, $actor, $data, announce: ! ($locked->metadata['shopify']['historical'] ?? false));
                    $mappings->link($mapping, 'order_payment', (int) $payment->id);
                }
            });
        } catch (ValidationException $error) {
            throw new PermanentError('Shopify payments need review: '.implode(' ', $error->validator->errors()->all()));
        }
    }
}
