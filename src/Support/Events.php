<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Support;

use App\Models\Order\Order;
use Illuminate\Support\Facades\Log;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use Throwable;

/**
 * The plugin's outbound webhook events, sent through core's webhooks
 * (signed, retried, delivery-logged) when the running Inventoros supports
 * plugin events (register_webhook_event, Inventoros#251).
 *
 *   shopify.order_imported  a Shopify order became an Inventoros order
 *   shopify.sync_failed     sync work gave up and is waiting in the error list
 */
final class Events
{
    public const ORDER_IMPORTED = 'shopify.order_imported';

    public const SYNC_FAILED = 'shopify.sync_failed';

    public static function supported(): bool
    {
        return function_exists('register_webhook_event') && function_exists('dispatch_webhook_event');
    }

    public static function register(): void
    {
        if (! self::supported()) {
            return;
        }

        register_webhook_event(self::ORDER_IMPORTED, 'A Shopify order was imported as an Inventoros order', 'Shopify');
        register_webhook_event(self::SYNC_FAILED, 'Shopify sync work failed and needs attention', 'Shopify');
    }

    public static function orderImported(Connection $connection, Order $order, string $shopifyId, string $shopifyName): void
    {
        self::send(self::ORDER_IMPORTED, [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'shopify_order_id' => $shopifyId,
            'shopify_order_name' => $shopifyName,
            'shop' => $connection->shop_domain,
            'connection_id' => $connection->id,
            'total' => $order->total,
            'currency' => $order->currency,
        ], $connection->organization_id);
    }

    public static function syncFailed(Connection $connection, DeadLetter $letter): void
    {
        self::send(self::SYNC_FAILED, [
            'connection_id' => $connection->id,
            'shop' => $connection->shop_domain,
            'kind' => $letter->kind,
            'reference' => $letter->reference,
            'error' => $letter->error,
            'failed_item_id' => $letter->id,
        ], $connection->organization_id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function send(string $event, array $data, int $organizationId): void
    {
        if (! self::supported()) {
            return;
        }

        try {
            dispatch_webhook_event($event, $data, $organizationId);
        } catch (Throwable $error) {
            // Never let an outbound notification fail the sync itself.
            Log::warning('Shopify plugin could not send a webhook event', ['event' => $event, 'error' => $error->getMessage()]);
        }
    }
}
