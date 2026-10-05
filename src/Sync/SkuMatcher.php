<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Sync;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use Inventoros\Plugins\Shopify\Support\Core;

/**
 * Inventoros records with a given SKU in one organization: variants, and
 * products that are not sold by variant. Exact match, after trimming.
 */
final class SkuMatcher
{
    /**
     * @return array<int, array{type: string, id: int, label: string}>
     */
    public static function candidates(int $organizationId, string $sku): array
    {
        $sku = trim($sku);

        if ($sku === '') {
            return [];
        }

        $found = [];

        Core::variants($organizationId)
            ->where('sku', $sku)
            ->with('product:id,name')
            ->limit(5)
            ->get()
            ->each(function (ProductVariant $variant) use (&$found) {
                $found[] = [
                    'type' => 'variant',
                    'id' => (int) $variant->id,
                    'label' => trim(($variant->product?->name ?? '').' / '.($variant->title ?? $variant->sku), ' /'),
                ];
            });

        Core::products($organizationId)
            ->where('sku', $sku)
            ->where(fn ($q) => $q->where('has_variants', false)->orWhereNull('has_variants'))
            ->limit(5)
            ->get(['id', 'name'])
            ->each(function (Product $product) use (&$found) {
                $found[] = ['type' => 'product', 'id' => (int) $product->id, 'label' => (string) $product->name];
            });

        return $found;
    }
}
