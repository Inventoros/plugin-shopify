<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Sync;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Services\Inventory\KitSaleService;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Kit\Outbox\Outbox;
use Inventoros\Plugins\Shopify\Support\Core;

/**
 * Kits on cores that sell them from their components (Inventoros 2.7+).
 *
 * There a kit holds no stock of its own: selling one takes its components,
 * so Shopify is told how many kits the components can make (on hand less
 * reservations, the number core itself sells against), and a kit is pushed
 * again whenever one of its components moves. Older cores sell a kit from
 * its own stock, so it is pushed like any other product.
 */
final class KitStock
{
    public static function supported(): bool
    {
        return class_exists(KitSaleService::class) && method_exists(KitSaleService::class, 'available');
    }

    public static function isKit(mixed $target): bool
    {
        return $target instanceof Product && $target->type === 'kit' && self::supported();
    }

    /**
     * Whole kits the components can make now; 0 for a kit core cannot sell.
     */
    public static function available(Product $kit): int
    {
        return max(0, app(KitSaleService::class)->available($kit));
    }

    /**
     * Ids of this organization's kits that have any of these products as a
     * component, in ascending order.
     *
     * @param  list<int>  $productIds
     * @return list<int>
     */
    public static function kitsUsing(int $organizationId, array $productIds): array
    {
        if ($productIds === [] || ! self::supported()) {
            return [];
        }

        return ProductComponent::query()
            ->whereIn('component_product_id', $productIds)
            ->whereIn('parent_product_id', Core::products($organizationId)->where('type', 'kit')->select('products.id'))
            ->distinct()
            ->orderBy('parent_product_id')
            ->pluck('parent_product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Queue a stock push for every mapped kit of the connection.
     *
     * Core reports no hook when a kit's component list is edited, so the
     * scheduled sync calls this: a changed bill of materials reaches Shopify
     * within one schedule interval. Pushes are absolute, so an unchanged kit
     * is simply set to the same quantity again.
     */
    public static function queueMapped(Connection $connection): int
    {
        if (! self::supported()) {
            return 0;
        }

        $ids = Mapping::query()
            ->where('connection_id', $connection->id)
            ->where('entity', Catalog::ENTITY)
            ->where('status', Mapping::MAPPED)
            ->where('internal_type', 'product')
            ->whereIn('internal_id', Core::products($connection->organization_id)->where('type', 'kit')->select('products.id'))
            ->orderBy('internal_id')
            ->pluck('internal_id');

        $outbox = app(Outbox::class);
        foreach ($ids as $id) {
            $outbox->put($connection, InventoryPush::KIND, InventoryPush::subject('product', (int) $id));
        }

        return $ids->count();
    }
}
