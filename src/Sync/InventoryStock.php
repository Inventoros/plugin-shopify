<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Sync;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Services\ProductLocationStockService;
use App\Services\StockReservationService;
use Illuminate\Support\Collection;
use Inventoros\Plugins\Shopify\Support\Core;

/** Core capability adapter: older releases keep variants at the primary Shopify location. */
final class InventoryStock
{
    public static function supportsVariantBins(): bool
    {
        return class_exists(\App\Models\Inventory\VariantLocationStock::class);
    }

    public function rows(Product|ProductVariant $target): Collection
    {
        $rows = app(ProductLocationStockService::class)->breakdown($target)
            ->filter(fn ($row) => $row->location !== null)
            ->map(fn ($row) => ['location_id' => (int) $row->location_id, 'warehouse_id' => $row->location->warehouse_id, 'quantity' => (int) $row->quantity]);
        if ($rows->isEmpty()) {
            $product = $target instanceof ProductVariant ? Core::products((int) $target->organization_id)->find($target->product_id) : $target;
            $location = $product?->location_id ? Core::locations((int) $target->organization_id)->find($product->location_id) : null;
            if ($location !== null) {
                $rows->push(['location_id' => (int) $location->id, 'warehouse_id' => $location->warehouse_id, 'quantity' => (int) $target->stock]);
            }
        }

        return $rows->values();
    }

    public function held(Product|ProductVariant $target): int
    {
        return class_exists(StockReservationService::class)
            ? app(StockReservationService::class)->reservedTotal($target instanceof ProductVariant ? (int) $target->product_id : (int) $target->id, $target instanceof ProductVariant ? (int) $target->id : null)
            : 0;
    }

    public function freeByWarehouse(Product|ProductVariant $target): Collection
    {
        $holds = class_exists(StockReservationService::class)
            ? app(StockReservationService::class)->reservedByLocation($target instanceof ProductVariant ? (int) $target->product_id : (int) $target->id, $target instanceof ProductVariant ? (int) $target->id : null)
            : [];

        return $this->rows($target)->groupBy('warehouse_id')
            ->map(fn ($rows) => (int) $rows->sum(fn ($row) => max(0, $row['quantity'] - ($holds[$row['location_id']] ?? 0))));
    }
}
