<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Sync;

use App\Models\Shipping\Shipment;
use App\Services\HookRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Kit\Exceptions\RateLimited;
use Inventoros\Plugins\Shopify\Kit\Mapping\MappingStore;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Kit\Outbox\Outbox;
use Inventoros\Plugins\Shopify\Kit\Sync\SyncRecorder;
use Inventoros\Plugins\Shopify\Shopify\ClientFactory;
use Inventoros\Plugins\Shopify\Shopify\Gid;
use Inventoros\Plugins\Shopify\Shopify\SyncPolicy;
use Inventoros\Plugins\Shopify\Support\Core;
use Throwable;

final class FulfilmentPush
{
    public const KIND = 'fulfilment';

    public static function supported(): bool
    {
        return array_key_exists('shipment_should_notify_customer', HookRegistry::getFilters());
    }

    public static function connectionFor(Shipment $shipment): ?Connection
    {
        $order = Core::orders((int) $shipment->organization_id)->find($shipment->order_id);
        if ($order === null || $order->stock_committed === false || ($order->metadata['shopify']['historical'] ?? false)) {
            return null;
        }
        $id = $order?->metadata['shopify']['connection_id'] ?? null;
        $connection = $id ? Connection::forOrganization((int) $shipment->organization_id)->find($id) : null;
        if ($connection === null || $connection->status === Connection::DISCONNECTED || ! SyncPolicy::for($connection)->pushesFulfilments()) {
            return null;
        }

        return Mapping::where('connection_id', $connection->id)->where('entity', OrderImporter::ENTITY)
            ->where('internal_type', 'order')->where('internal_id', $shipment->order_id)->where('status', Mapping::MAPPED)->exists() ? $connection : null;
    }

    public function push(Connection $connection, $entries, $run): void
    {
        foreach ($entries as $entry) {
            $shipment = Shipment::withoutGlobalScopes()->where('organization_id', $connection->organization_id)->find((int) $entry->subject);
            if ($shipment === null || $shipment->shipped_at === null || $shipment->status->value === 'cancelled') {
                app(Outbox::class)->done($entry);

                continue;
            }
            $lock = Cache::lock('shopify:fulfil:'.$connection->id.':'.$shipment->order_id, 330);
            if (! $lock->get()) {
                throw new RateLimited('Another shipment of this order is syncing.', 3);
            }
            try {
                $record = Mapping::firstOrCreate([
                    'connection_id' => $connection->id, 'entity' => self::KIND, 'external_id' => 'shipment-'.$shipment->id,
                ], ['organization_id' => $connection->organization_id, 'internal_type' => 'shipment', 'internal_id' => $shipment->id, 'status' => Mapping::PENDING]);
                if ($record->meta('state') === 'ambiguous' || $record->meta('state') === 'sending') {
                    app(Outbox::class)->done($entry);
                    throw new PermanentError("Shipment {$shipment->id} needs review: Shopify may already have created its fulfilment. Check and complete it in Shopify; automatic creation is stopped to prevent duplicates.");
                }
                if ($record->meta('fulfilment_id') === null) {
                    $this->send($connection, $shipment, $record);
                }
                app(Outbox::class)->done($entry);
                app(SyncRecorder::class)->count($run, 'processed');
                app(SyncRecorder::class)->log($connection, $run, 'info', "Shipment {$shipment->id} fulfilled in Shopify.", ['entity' => self::KIND, 'internal_id' => $shipment->id, 'external_id' => $record->fresh()->meta('fulfilment_id'), 'action' => 'push_fulfilment']);
            } catch (Throwable $e) {
                if (isset($record) && $record->fresh()->meta('state') === 'ambiguous') {
                    app(Outbox::class)->done($entry);
                }
                throw $e;
            } finally {
                $lock->release();
            }
        }
    }

    private function send(Connection $connection, Shipment $shipment, Mapping $record): void
    {
        if (! self::supported()) {
            throw new PermanentError('Update Inventoros to a core with shipment notification ownership before enabling fulfilment sync.');
        }
        $order = Core::orders((int) $connection->organization_id)->findOrFail($shipment->order_id);
        $orderMapping = app(MappingStore::class)->forInternal($connection, OrderImporter::ENTITY, 'order', (int) $order->id);
        if ($orderMapping === null || self::connectionFor($shipment)?->id !== $connection->id) {
            throw new PermanentError('This shipment is not linked to an imported order for this connection.');
        }
        $location = app(Locations::class)->mapped($connection)->where('internal_type', Locations::WAREHOUSE)->where('internal_id', $shipment->warehouse_id);
        if ($shipment->warehouse_id === null || $location->count() !== 1) {
            throw new PermanentError('Map the shipment warehouse to exactly one Shopify location before syncing it.');
        }
        $locationId = (string) $location->first()->external_id;
        $lineIds = $order->metadata['shopify']['line_items'] ?? [];
        $wanted = [];
        foreach ($shipment->items as $item) {
            $external = $lineIds[$item->order_item_id] ?? null;
            if ($external === null || (int) $item->quantity <= 0) {
                throw new PermanentError('This shipment has no exact Shopify line mapping. Fulfil older imported orders in Shopify.');
            }
            $wanted[$external] = ($wanted[$external] ?? 0) + (int) $item->quantity;
        }
        if ($wanted === []) {
            throw new PermanentError('The shipment contains no fulfilment lines.');
        }
        $client = app(ClientFactory::class)->make($connection);
        // Moves may split/merge fulfillment orders and replace their line ids.
        // Re-read after each move, including after a retried/lost move response.
        for ($moves = 0; $moves < 101; $moves++) {
            $orders = $client->fulfilmentOrders($orderMapping->external_id, $locationId);
            $remaining = $wanted;
            $groups = [];
            $move = null;
            usort($orders, fn ($a, $b) => ($a['location_id'] !== $locationId) <=> ($b['location_id'] !== $locationId));
            foreach ($orders as $fulfilmentOrder) {
                if (! in_array($fulfilmentOrder['status'], ['OPEN', 'IN_PROGRESS'], true) || ! in_array($fulfilmentOrder['request_status'], ['UNSUBMITTED', 'REJECTED', 'CANCELLATION_ACCEPTED'], true)) {
                    continue;
                }
                $lines = [];
                $activate = [];
                foreach ($fulfilmentOrder['lines'] as $line) {
                    $quantity = min($remaining[$line['line_id']] ?? 0, $line['remaining']);
                    if ($quantity <= 0) {
                        continue;
                    }
                    $lines[] = ['id' => $line['id'], 'quantity' => $quantity];
                    $remaining[$line['line_id']] -= $quantity;
                    if (! $line['stocked'] && $line['inventory_item_id'] !== null) {
                        $activate[] = $line['inventory_item_id'];
                    }
                }
                if ($lines === []) {
                    continue;
                }
                if ($fulfilmentOrder['location_id'] !== $locationId) {
                    $move = [$fulfilmentOrder['id'], $lines, $activate];
                    break;
                }
                $groups[] = ['fulfillmentOrderId' => Gid::make('FulfillmentOrder', $fulfilmentOrder['id']), 'fulfillmentOrderLineItems' => array_map(fn ($line) => ['id' => Gid::make('FulfillmentOrderLineItem', $line['id']), 'quantity' => $line['quantity']], $lines)];
            }
            if ($move !== null) {
                if ($moves === 100) {
                    throw new PermanentError('Too many fulfilment moves. Finish the shipment in Shopify.');
                }
                foreach (array_unique($move[2]) as $itemId) {
                    $client->activateInventory($itemId, $locationId, 'inventoros-'.$connection->id.'-'.$shipment->id.'-'.$itemId.'-'.$locationId);
                }
                $client->moveFulfilmentOrder($move[0], $locationId, $move[1]);

                continue;
            }
            if (array_sum($remaining) > 0 || $groups === []) {
                throw new PermanentError('Shopify has insufficient unfulfilled quantities for this shipment. Check existing fulfilments and held fulfillment orders.');
            }
            $input = ['lineItemsByFulfillmentOrder' => $groups, 'notifyCustomer' => $shipment->notify_customer && $shipment->customer_notified_at === null && $shipment->customer_notification_queued_at === null];
            if (filled($shipment->tracking_number)) {
                $input['trackingInfo'] = array_filter(['number' => $shipment->tracking_number, 'url' => $shipment->tracking_url, 'company' => $shipment->carrier_name], fn ($value) => filled($value));
            }
            // Durable claim before the non-idempotent request: a killed worker
            // or ambiguous network response cannot blindly create a second parcel.
            DB::transaction(function () use ($record, $input): void {
                $locked = Mapping::whereKey($record->id)->lockForUpdate()->firstOrFail();
                if (in_array($locked->meta('state'), ['sending', 'ambiguous', 'done'], true)) {
                    throw new PermanentError('This shipment already has a send claim. Review its Shopify fulfilment.');
                }
                $locked->update(['meta' => array_merge($locked->meta ?? [], ['state' => 'sending', 'input' => $input, 'claimed_at' => now()->toIso8601String()])]);
            });
            try {
                $id = $client->createFulfilment($input, 'Inventoros shipment '.$shipment->id);
            } catch (RateLimited $e) {
                $record->refresh()->update(['meta' => array_merge($record->meta ?? [], ['state' => 'pending'])]);
                throw $e;
            } catch (Throwable $e) {
                $record->refresh()->update(['status' => Mapping::CONFLICT, 'meta' => array_merge($record->meta ?? [], ['state' => 'ambiguous'])]);
                throw new PermanentError("Shipment {$shipment->id}: verify and finish its fulfilment in Shopify before retrying. Automatic creation stopped after an uncertain or rejected response: ".$e->getMessage());
            }
            $record->refresh()->update(['status' => Mapping::MAPPED, 'synced_at' => now(), 'meta' => array_merge($record->meta ?? [], ['state' => 'done', 'fulfilment_id' => $id])]);
            if ($input['notifyCustomer']) {
                $shipment->update(['customer_notification_queued_at' => now()]);
            }

            return;
        }
    }
}
