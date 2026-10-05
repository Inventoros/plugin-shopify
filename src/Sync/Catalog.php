<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Sync;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use Illuminate\Support\Carbon;
use Inventoros\Plugins\Shopify\Kit\Mapping\MappingConflict;
use Inventoros\Plugins\Shopify\Kit\Mapping\MappingStore;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Kit\Models\SyncRun;
use Inventoros\Plugins\Shopify\Kit\Sync\SyncRecorder;
use Inventoros\Plugins\Shopify\Shopify\SyncPolicy;
use Inventoros\Plugins\Shopify\Support\Core;
use Throwable;

/**
 * Shopify products and variants against Inventoros products and variants.
 *
 * Every Shopify variant gets a mapping row. A variant whose SKU matches
 * exactly one Inventoros variant (or simple product) is SUGGESTED; a person
 * confirms suggestions on the Mappings page (the review step) before
 * anything syncs. No SKU, no match, several matches, or a match already
 * taken by another Shopify variant are left for review, never guessed.
 *
 * When Shopify owns products (SyncPolicy), changes to mapped products
 * update the Inventoros name and prices; when Inventoros owns them, the
 * Shopify side only refreshes the mapping.
 */
final class Catalog
{
    public const ENTITY = 'variant';

    public function __construct(
        private readonly MappingStore $mappings,
        private readonly SyncRecorder $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $product  Normalizer::product() shape
     */
    public function ingest(Connection $connection, array $product, SyncRun $run, bool $applyChanges): void
    {
        $updatedAt = isset($product['updated_at']) ? Carbon::parse($product['updated_at']) : null;
        $policy = SyncPolicy::for($connection);
        $variants = $product['variants'] ?? [];

        foreach ($variants as $variant) {
            if (($variant['id'] ?? null) === null) {
                continue;
            }

            $this->recorder->count($run, 'processed');

            try {
                $outcome = $this->ingestVariant($connection, $product, $variant, $updatedAt, $policy, $applyChanges, count($variants) === 1);
                $this->recorder->count($run, $outcome);
            } catch (Throwable $error) {
                $this->recorder->count($run, 'failed');
                $this->recorder->log($connection, $run, 'error', "Variant {$variant['id']} ({$variant['sku']}): {$error->getMessage()}", [
                    'entity' => self::ENTITY,
                    'external_id' => $variant['id'],
                    'action' => 'ingest',
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $variant
     * @return string the counter to bump (created, updated, skipped)
     */
    private function ingestVariant(Connection $connection, array $product, array $variant, ?Carbon $updatedAt, SyncPolicy $policy, bool $applyChanges, bool $only): string
    {
        $existing = $this->mappings->find($connection, self::ENTITY, $variant['id']);

        if ($existing !== null && $this->mappings->isStale($existing, $updatedAt)) {
            $this->recorder->log($connection, null, 'info', "Ignored an older update of variant {$variant['id']}.", [
                'entity' => self::ENTITY, 'external_id' => $variant['id'], 'action' => 'stale',
            ]);

            return 'skipped';
        }

        $label = $only || $variant['title'] === '' || $variant['title'] === 'Default Title'
            ? $product['title']
            : $product['title'].' / '.$variant['title'];

        $mapping = $this->mappings->remember($connection, self::ENTITY, $variant['id'], [
            'external_parent_id' => $product['id'],
            'external_label' => $label,
            'sku' => $variant['sku'],
            'meta' => [
                'inventory_item_id' => $variant['inventory_item_id'],
                'product_title' => $product['title'],
                'variant_title' => $variant['title'],
                'price' => $variant['price'],
                'barcode' => $variant['barcode'],
                'options' => $variant['options'],
            ],
        ]);

        if (in_array($mapping->status, [Mapping::PENDING, Mapping::UNMATCHED, Mapping::CONFLICT], true)) {
            $this->match($connection, $mapping);
        } elseif ($mapping->isMapped() && $applyChanges && $policy->pullsProducts()) {
            $this->applyToCore($connection, $mapping, $product, $variant);
        }

        $this->mappings->touch($mapping, $updatedAt);

        return $existing === null ? 'created' : 'updated';
    }

    /**
     * Suggest the Inventoros record with the same SKU, when there is exactly one.
     */
    public function match(Connection $connection, Mapping $mapping): Mapping
    {
        if ($mapping->sku === null || $mapping->sku === '') {
            return $this->mappings->unlink($mapping, Mapping::UNMATCHED);
        }

        $candidates = SkuMatcher::candidates($connection->organization_id, $mapping->sku);

        if ($candidates === []) {
            return $this->mappings->unlink($mapping, Mapping::UNMATCHED);
        }

        if (count($candidates) > 1) {
            $this->noteConflict($mapping, 'Several Inventoros records have this SKU.');

            return $this->mappings->unlink($mapping, Mapping::CONFLICT);
        }

        try {
            $this->mappings->link($mapping, $candidates[0]['type'], $candidates[0]['id'], Mapping::SUGGESTED);
            $this->noteConflict($mapping, null);
        } catch (MappingConflict $conflict) {
            $this->noteConflict($mapping, $conflict->getMessage());
            $this->mappings->unlink($mapping->refresh(), Mapping::CONFLICT);
        }

        return $mapping->refresh();
    }

    /**
     * Shopify owns products: copy the title and price of a changed Shopify
     * variant onto its Inventoros record (prices only in the same currency).
     *
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $variant
     */
    private function applyToCore(Connection $connection, Mapping $mapping, array $product, array $variant): void
    {
        $shopCurrency = strtoupper((string) (($connection->remote ?? [])['currency'] ?? ''));

        if ($mapping->internal_type === 'variant') {
            $core = Core::variants($connection->organization_id)->find($mapping->internal_id);
            $parent = $core?->product;
        } else {
            $core = Core::products($connection->organization_id)->find($mapping->internal_id);
            $parent = $core;
        }

        if ($core === null || $parent === null) {
            $this->mappings->unlink($mapping, Mapping::UNMATCHED);

            return;
        }

        $changed = [];

        if ($product['title'] !== '' && $parent->name !== $product['title']) {
            $parent->name = $product['title'];
            $parent->save();
            $changed[] = 'name';
        }

        $sameCurrency = $shopCurrency === '' || strtoupper((string) $parent->currency) === $shopCurrency;

        if ($sameCurrency && $variant['price'] !== null) {
            if ($core instanceof ProductVariant && (string) $core->price !== $variant['price']) {
                $core->price = $variant['price'];
                $core->save();
                $changed[] = 'price';
            } elseif ($core instanceof Product && $core->selling_price === null && (string) $core->price !== $variant['price']) {
                $core->price = $variant['price'];
                $core->save();
                $changed[] = 'price';
            }
        }

        if ($changed !== []) {
            $this->recorder->log($connection, null, 'info', 'Updated '.implode(' and ', $changed)." of {$mapping->external_label} from Shopify.", [
                'entity' => self::ENTITY,
                'external_id' => $mapping->external_id,
                'internal_id' => $mapping->internal_id,
                'action' => 'pull_product',
            ]);
        }
    }

    private function noteConflict(Mapping $mapping, ?string $reason): void
    {
        $meta = $mapping->meta ?? [];

        if ($reason === null) {
            unset($meta['conflict']);
        } else {
            $meta['conflict'] = $reason;
        }

        $mapping->meta = $meta;
        $mapping->save();
    }
}
