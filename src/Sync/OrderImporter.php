<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Sync;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderItemException;
use App\Exceptions\InvalidStateException;
use App\Models\Order\Order;
use App\Services\OrderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Kit\Mapping\MappingStore;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Kit\Models\SyncRun;
use Inventoros\Plugins\Shopify\Kit\Sync\SyncRecorder;
use Inventoros\Plugins\Shopify\Shopify\SyncPolicy;
use Inventoros\Plugins\Shopify\Support\Core;
use Inventoros\Plugins\Shopify\Support\Events;

/**
 * Imports Shopify orders as Inventoros orders, exactly once.
 *
 * An order is created through core's OrderService (which locks stock,
 * writes a ledger row per line, draws the units out of their bins and
 * fires order_created) with source "shopify", external_id the Shopify id
 * and external_reference "shopify:{shop}:{id}". The order mapping row is
 * locked while the order is created, and core's unique external_reference
 * backs it up, so concurrent or repeated deliveries create one order.
 *
 * Cancellation in Shopify cancels the Inventoros order through
 * OrderService::cancel(), which restocks it (once). An order cancelled
 * before it was ever imported is remembered as ignored, so a late
 * orders/create does not import it. Every delivery is compared with the
 * newest update already applied: older ones are ignored.
 *
 * Lines whose Shopify variant is not mapped stop the import with an error
 * that names the SKU; it is retried from the error list once mapped.
 */
final class OrderImporter
{
    public const ENTITY = 'order';

    public function __construct(
        private readonly MappingStore $mappings,
        private readonly Locations $locations,
        private readonly SyncRecorder $recorder,
        private readonly OrderService $orders,
    ) {}

    public static function reference(Connection $connection, string $orderId): string
    {
        return 'shopify:'.$connection->shop_domain.':'.$orderId;
    }

    /**
     * @param  array<string, mixed>  $order  Normalizer::order() shape
     * @return string created, cancelled, unchanged, stale or skipped
     */
    public function import(Connection $connection, array $order, ?SyncRun $run = null): string
    {
        $id = (string) ($order['id'] ?? '');
        if ($id === '') {
            return 'skipped';
        }

        $policy = SyncPolicy::for($connection);
        $updatedAt = $this->time($order['updated_at'] ?? null);
        $label = $order['name'] !== '' ? $order['name'] : "#{$id}";

        $existing = $this->mappings->find($connection, self::ENTITY, $id);
        if ($existing !== null && $this->mappings->isStale($existing, $updatedAt)) {
            $this->log($connection, $run, 'info', "Ignored an older update of order {$label}.", $id, $existing->internal_id, 'stale');

            return 'stale';
        }

        if ($existing !== null && $existing->internal_id !== null) {
            return $this->update($connection, $existing, $order, $updatedAt, $label, $run);
        }

        if ($existing !== null && $existing->status === Mapping::IGNORED) {
            $this->mappings->touch($existing, $updatedAt);

            return 'skipped';
        }

        if (! $policy->importsOrders()) {
            return 'skipped';
        }

        if (($order['test'] ?? false) && ! $policy->importsTestOrders()) {
            return $this->ignore($connection, $id, $label, $updatedAt, 'test order', $run);
        }

        $since = $policy->ordersSince();
        $createdAt = $this->time($order['created_at'] ?? null);
        if ($since !== null && $createdAt !== null && $createdAt->lt(Carbon::parse($since)->startOfDay())) {
            return $this->ignore($connection, $id, $label, $updatedAt, "placed before {$since}", $run);
        }

        if (($order['cancelled_at'] ?? null) !== null) {
            return $this->ignore($connection, $id, $label, $updatedAt, 'cancelled before it was imported', $run);
        }

        return $this->create($connection, $id, $order, $updatedAt, $label, $run);
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function create(Connection $connection, string $id, array $order, ?Carbon $updatedAt, string $label, ?SyncRun $run): string
    {
        $actor = Core::actor($connection);
        [$items, $skippedLines] = $this->items($connection, $order, $label);

        if ($items === []) {
            return $this->ignore($connection, $id, $label, $updatedAt, 'no line items that ship from stock', $run);
        }

        $reference = self::reference($connection, $id);
        $mapping = $this->mappings->remember($connection, self::ENTITY, $id, ['external_label' => $label]);

        try {
            $created = DB::transaction(function () use ($connection, $mapping, $reference, $order, $items, $actor, $label) {
                // Serialize concurrent imports of the same order on its mapping row.
                $locked = Mapping::query()->whereKey($mapping->id)->lockForUpdate()->first();

                if ($locked->internal_id !== null) {
                    return null;
                }

                $duplicate = Core::orders($connection->organization_id)->where('external_reference', $reference)->first();
                if ($duplicate !== null) {
                    $this->mappings->link($locked, 'order', (int) $duplicate->id);

                    return null;
                }

                $created = $this->orders->create($this->orderData($connection, $order, $items, $reference, $label), $actor, 'shopify');
                $this->mappings->link($locked, 'order', (int) $created->id);

                return $created;
            });
        } catch (InsufficientStockException $error) {
            throw new PermanentError("Order {$label}: not enough stock in Inventoros ({$error->getMessage()}). Adjust the stock, then retry.");
        } catch (InvalidOrderItemException|BusinessRuleException|InvalidStateException $error) {
            throw new PermanentError("Order {$label}: {$error->getMessage()}");
        } catch (ValidationException $error) {
            throw new PermanentError("Order {$label}: ".implode(' ', $error->validator->errors()->all()));
        }

        $this->mappings->touch($mapping->refresh(), $updatedAt);

        if ($created === null) {
            $this->log($connection, $run, 'info', "Order {$label} was already imported.", $id, $mapping->internal_id, 'duplicate');

            return 'unchanged';
        }

        foreach ($skippedLines as $line) {
            $this->log($connection, $run, 'warning', "Order {$label}: left out \"{$line['title']}\" (no Shopify product variant, so nothing to take from stock).", $id, $created->id, 'skip_line');
        }

        $this->log($connection, $run, 'info', "Imported order {$label} as {$created->order_number}.", $id, (int) $created->id, 'import_order');

        Events::orderImported($connection, $created, $id, $label);

        return 'created';
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function update(Connection $connection, Mapping $mapping, array $order, ?Carbon $updatedAt, string $label, ?SyncRun $run): string
    {
        $core = Core::orders($connection->organization_id)->find($mapping->internal_id);

        if ($core === null) {
            // The Inventoros order was deleted: never re-create it.
            $this->mappings->touch($mapping, $updatedAt);

            return 'skipped';
        }

        $outcome = 'unchanged';

        if (($order['cancelled_at'] ?? null) !== null && $core->status !== OrderStatus::CANCELLED) {
            try {
                Core::cancelOrder($this->orders, $core, Core::actor($connection));
                $outcome = 'cancelled';
                $this->log($connection, $run, 'info', "Cancelled order {$core->order_number} (Shopify {$label}) and restocked it.", (string) $order['id'], (int) $core->id, 'cancel_order');
            } catch (InvalidStateException $error) {
                $this->log($connection, $run, 'warning', "Shopify cancelled {$label}, but Inventoros order {$core->order_number} cannot be cancelled: {$error->getMessage()}", (string) $order['id'], (int) $core->id, 'cancel_order');
            }
        }

        $this->mappings->touch($mapping, $updatedAt);

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function items(Connection $connection, array $order, string $label): array
    {
        $items = [];
        $skipped = [];

        foreach ($order['line_items'] ?? [] as $line) {
            if ($line['quantity'] <= 0) {
                continue;
            }

            if ($line['variant_id'] === null) {
                $skipped[] = $line;

                continue;
            }

            $mapping = $this->mappings->find($connection, Catalog::ENTITY, $line['variant_id']);

            if ($mapping === null || ! $mapping->isMapped()) {
                $sku = $line['sku'] ?? 'no SKU';

                throw new PermanentError("Order {$label}: \"{$line['title']}\" ({$sku}, Shopify variant {$line['variant_id']}) is not mapped to an Inventoros product. Map it on the Mappings page, then retry.");
            }

            if ($mapping->internal_type === 'variant') {
                $variant = Core::variants($connection->organization_id)->find($mapping->internal_id);
                if ($variant === null) {
                    throw new PermanentError("Order {$label}: the Inventoros variant mapped to {$line['title']} no longer exists. Map it again, then retry.");
                }
                $item = ['product_id' => (int) $variant->product_id, 'product_variant_id' => (int) $variant->id];
            } else {
                $item = ['product_id' => (int) $mapping->internal_id];
            }

            $item['quantity'] = (int) $line['quantity'];
            $item['unit_price'] = $line['price'];

            if ((float) $line['discount'] > 0) {
                $item['discount_type'] = 'fixed';
                $item['discount_value'] = $line['discount'];
            }

            $items[] = $item;
        }

        return [$items, $skipped];
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function orderData(Connection $connection, array $order, array $items, string $reference, string $label): array
    {
        $created = $this->time($order['created_at'] ?? null) ?? now();

        return array_filter([
            'items' => $items,
            'customer_name' => $order['customer_name'] ?? $order['email'] ?? 'Shopify customer',
            'customer_email' => $order['email'] ?? null,
            'customer_address' => $order['address'] ?? null,
            'status' => OrderStatus::PENDING->value,
            'order_date' => $created->toDateString(),
            'warehouse_id' => $this->locations->orderWarehouseId($connection),
            'currency' => $order['currency'] !== '' ? $order['currency'] : null,
            'tax' => $order['tax'],
            'shipping' => $order['shipping'],
            'notes' => "Imported from Shopify order {$label}.",
            'external_id' => (string) $order['id'],
            'external_reference' => $reference,
            'metadata' => [
                'shopify' => [
                    'connection_id' => $connection->id,
                    'shop' => $connection->shop_domain,
                    'order_id' => (string) $order['id'],
                    'name' => $label,
                    'financial_status' => $order['financial_status'] ?? null,
                    'fulfillment_status' => $order['fulfillment_status'] ?? null,
                    'total_discounts' => $order['discount'] ?? null,
                    'note' => $order['note'] ?? null,
                ],
            ],
        ], fn ($value) => $value !== null);
    }

    private function ignore(Connection $connection, string $id, string $label, ?Carbon $updatedAt, string $why, ?SyncRun $run): string
    {
        $mapping = $this->mappings->remember($connection, self::ENTITY, $id, ['external_label' => $label, 'status' => Mapping::IGNORED]);
        if ($mapping->status !== Mapping::IGNORED && $mapping->internal_id === null) {
            $this->mappings->mark($mapping, Mapping::IGNORED);
        }
        $this->mappings->touch($mapping, $updatedAt);

        $this->log($connection, $run, 'info', "Did not import order {$label}: {$why}.", $id, null, 'skip_order');

        return 'skipped';
    }

    private function log(Connection $connection, ?SyncRun $run, string $level, string $message, string $externalId, ?int $internalId, string $action): void
    {
        $this->recorder->log($connection, $run, $level, $message, [
            'entity' => self::ENTITY,
            'external_id' => $externalId,
            'internal_id' => $internalId,
            'action' => $action,
        ]);
    }

    private function time(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
