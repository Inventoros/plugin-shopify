<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Support;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Shipping\Shipment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Inventoros\Plugins\Shopify\Jobs\PushFulfilments;
use Inventoros\Plugins\Shopify\Jobs\PushInventory;
use Inventoros\Plugins\Shopify\Jobs\PushProducts;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Kit\Outbox\Outbox;
use Inventoros\Plugins\Shopify\Shopify\SyncPolicy;
use Inventoros\Plugins\Shopify\Sync\Catalog;
use Inventoros\Plugins\Shopify\Sync\FulfilmentPush;
use Inventoros\Plugins\Shopify\Sync\InventoryPush;
use Inventoros\Plugins\Shopify\Sync\ProductPush;
use Throwable;

/**
 * Core hook listeners. They run inside Inventoros requests (after the
 * change committed), so they only record that something needs pushing
 * and queue a delayed push: no call to Shopify ever happens here, and a
 * failure here is logged, never thrown into the core request.
 */
final class Listeners
{
    /** @var array<int, Collection<int, Connection>> connected stores per organization, per request */
    private static array $connections = [];

    /**
     * stock_changed($product, $variant, $change): queue a stock push for
     * every connection that owns stock in Inventoros and maps this item.
     *
     * @param  array<string, mixed>  $change
     */
    public static function stockChanged(mixed $product, mixed $variant = null, mixed $change = []): void
    {
        if (! $product instanceof Product) {
            return;
        }

        self::guard(function () use ($product, $variant) {
            [$type, $id] = $variant instanceof ProductVariant ? ['variant', (int) $variant->id] : ['product', (int) $product->id];

            foreach (self::connected((int) $product->organization_id) as $connection) {
                $policy = SyncPolicy::for($connection);

                if (! $policy->pushesStock() || ! self::isMapped($connection, $type, $id)) {
                    continue;
                }

                app(Outbox::class)->put($connection, InventoryPush::KIND, InventoryPush::subject($type, $id), [], $policy->pushDelay());
                PushInventory::dispatch($connection->id)->delay(now()->addSeconds($policy->pushDelay()));
            }
        });
    }

    /**
     * product_updated / variant_updated: queue a title and price push when
     * Inventoros owns products.
     */
    public static function catalogChanged(mixed $record): void
    {
        if ($record instanceof ProductVariant) {
            [$type, $id, $org] = ['variant', (int) $record->id, (int) $record->organization_id];
        } elseif ($record instanceof Product) {
            [$type, $id, $org] = ['product', (int) $record->id, (int) $record->organization_id];
        } else {
            return;
        }

        self::guard(function () use ($type, $id, $org) {
            foreach (self::connected($org) as $connection) {
                $policy = SyncPolicy::for($connection);

                if (! $policy->pushesProducts()) {
                    continue;
                }

                $mapped = $type === 'variant'
                    ? self::isMapped($connection, 'variant', $id)
                    : self::isMapped($connection, 'product', $id) || Mapping::query()
                        ->where('connection_id', $connection->id)
                        ->where('entity', Catalog::ENTITY)
                        ->where('status', Mapping::MAPPED)
                        ->where('internal_type', 'variant')
                        ->whereIn('internal_id', Core::variants($org)->where('product_id', $id)->select('id'))
                        ->exists();

                if (! $mapped) {
                    continue;
                }

                app(Outbox::class)->put($connection, ProductPush::KIND, InventoryPush::subject($type, $id), [], $policy->pushDelay());
                PushProducts::dispatch($connection->id)->delay(now()->addSeconds($policy->pushDelay()));
            }
        });
    }

    public static function shipmentShipped(mixed $shipment): void
    {
        if (! $shipment instanceof Shipment) {
            return;
        }
        self::guard(function () use ($shipment): void {
            $connection = FulfilmentPush::connectionFor($shipment);
            if ($connection === null) {
                return;
            }
            app(Outbox::class)->put($connection, FulfilmentPush::KIND, (string) $shipment->id);
            PushFulfilments::dispatch($connection->id);
        });
    }

    public static function shouldNotifyShipment(bool $send, mixed $shipment): bool
    {
        return $send && (! $shipment instanceof Shipment || FulfilmentPush::connectionFor($shipment) === null);
    }

    /**
     * Forget the per-request connection cache (tests, long-running workers).
     */
    public static function flush(): void
    {
        self::$connections = [];
    }

    /**
     * @return Collection<int, Connection>
     */
    private static function connected(int $organizationId): Collection
    {
        if (app()->runningInConsole() || ! isset(self::$connections[$organizationId])) {
            // Workers and commands live long: always read fresh there.
            self::$connections[$organizationId] = Connection::query()
                ->forOrganization($organizationId)
                ->where('status', Connection::CONNECTED)
                ->get();
        }

        return self::$connections[$organizationId];
    }

    private static function isMapped(Connection $connection, string $type, int $id): bool
    {
        return Mapping::query()
            ->where('connection_id', $connection->id)
            ->where('entity', Catalog::ENTITY)
            ->where('status', Mapping::MAPPED)
            ->where('internal_type', $type)
            ->where('internal_id', $id)
            ->exists();
    }

    private static function guard(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $error) {
            Log::warning('Shopify plugin could not queue a push', ['error' => $error->getMessage()]);
        }
    }
}
