<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Sync;

use Illuminate\Support\Collection;
use Inventoros\Plugins\Shopify\Kit\Mapping\MappingStore;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Kit\Models\OutboxEntry;
use Inventoros\Plugins\Shopify\Kit\Models\SyncRun;
use Inventoros\Plugins\Shopify\Kit\Outbox\Outbox;
use Inventoros\Plugins\Shopify\Kit\Sync\SyncRecorder;
use Inventoros\Plugins\Shopify\Shopify\ClientFactory;
use Inventoros\Plugins\Shopify\Support\Core;

/**
 * Pushes Inventoros names and prices of mapped products to Shopify, when
 * Inventoros owns products (SyncPolicy). Coalesced through the outbox like
 * stock: one row per changed product or variant, current values sent.
 *
 * Only the product title and variant prices are pushed; Shopify keeps its
 * descriptions, media, options and SEO fields.
 */
final class ProductPush
{
    public const KIND = 'product';

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly MappingStore $mappings,
        private readonly Outbox $outbox,
        private readonly SyncRecorder $recorder,
    ) {}

    /**
     * @param  Collection<int, OutboxEntry>  $entries
     */
    public function push(Connection $connection, Collection $entries, SyncRun $run): void
    {
        $titles = [];
        $prices = [];
        $currency = strtoupper((string) (($connection->remote ?? [])['currency'] ?? ''));

        foreach ($entries as $entry) {
            $type = str_starts_with($entry->subject, 'v') ? 'variant' : 'product';
            $id = (int) substr($entry->subject, 1);

            if ($type === 'variant') {
                $variant = Core::variants($connection->organization_id)->with('product')->find($id);
                $mapping = $this->mappings->forInternal($connection, Catalog::ENTITY, 'variant', $id);

                if ($variant === null || $mapping === null) {
                    continue;
                }

                if ($variant->product !== null) {
                    $titles[$mapping->external_parent_id] = $variant->product->name;
                }

                if ($variant->price !== null && $this->sameCurrency($variant->product?->currency, $currency)) {
                    $prices[$mapping->external_parent_id][$mapping->external_id] = number_format((float) $variant->price, 2, '.', '');
                }

                continue;
            }

            $product = Core::products($connection->organization_id)->find($id);
            if ($product === null) {
                continue;
            }

            // A simple product maps directly; a product sold by variant is
            // reached through any of its mapped variants (for its title).
            $mapping = $this->mappings->forInternal($connection, Catalog::ENTITY, 'product', $id)
                ?? Mapping::query()
                    ->where('connection_id', $connection->id)
                    ->where('entity', Catalog::ENTITY)
                    ->where('status', Mapping::MAPPED)
                    ->where('internal_type', 'variant')
                    ->whereIn('internal_id', Core::variants($connection->organization_id)->where('product_id', $id)->select('id'))
                    ->first();

            if ($mapping === null || $mapping->external_parent_id === null) {
                continue;
            }

            $titles[$mapping->external_parent_id] = $product->name;

            $price = $product->selling_price ?? $product->price;
            if ($mapping->internal_type === 'product' && $price !== null && $this->sameCurrency($product->currency, $currency)) {
                $prices[$mapping->external_parent_id][$mapping->external_id] = number_format((float) $price, 2, '.', '');
            }
        }

        $client = ($titles === [] && $prices === []) ? null : $this->clients->make($connection);

        foreach ($titles as $productId => $title) {
            $client->updateProductTitle((string) $productId, (string) $title);
            $this->recorder->count($run, 'processed');
            $this->recorder->count($run, 'updated');
            $this->recorder->log($connection, $run, 'info', "Set the Shopify title of product {$productId} to \"{$title}\".", [
                'entity' => 'product', 'external_id' => $productId, 'action' => 'push_product',
            ]);
        }

        foreach ($prices as $productId => $variants) {
            $client->updateVariantPrices((string) $productId, array_map(
                fn ($variantId, $price) => ['id' => (string) $variantId, 'price' => $price],
                array_keys($variants),
                array_values($variants),
            ));
            $this->recorder->count($run, 'processed');
            $this->recorder->count($run, 'updated');
            $this->recorder->log($connection, $run, 'info', 'Updated '.count($variants)." Shopify price(s) of product {$productId}.", [
                'entity' => 'product', 'external_id' => $productId, 'action' => 'push_price',
            ]);
        }

        foreach ($entries as $entry) {
            $this->outbox->done($entry);
        }
    }

    private function sameCurrency(?string $productCurrency, string $shopCurrency): bool
    {
        return $shopCurrency === '' || strtoupper((string) $productCurrency) === $shopCurrency;
    }
}
