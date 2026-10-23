<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Shopify;

use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Kit\Exceptions\RateLimited;
use Inventoros\Plugins\Shopify\Kit\Exceptions\TransientError;

/**
 * Everything the plugin asks of Shopify, in the plugin's own terms.
 *
 * HttpShopifyClient implements it over the Admin GraphQL API (pinned to
 * API_VERSION); the tests use a fake built from recorded responses. No
 * other class talks to Shopify.
 *
 * Ids are Shopify's numeric ids as strings (the form webhooks use);
 * records come back normalized by Normalizer, so a product read through
 * GraphQL and one delivered by a webhook look the same.
 *
 * Every method may throw RateLimited (429, THROTTLED, or the local cost
 * budget is empty), TransientError (5xx, network) or PermanentError
 * (401/403/404, validation errors).
 *
 * @throws RateLimited|TransientError|PermanentError
 */
interface ShopifyClient
{
    /** The Admin API version every request is pinned to. */
    public const API_VERSION = '2026-07';

    /**
     * @return array{id: string, name: string, domain: string, currency: string, plan: string|null, scopes: array<int, string>}
     */
    public function shop(): array;

    /**
     * @return Page<array{id: string, name: string, active: bool}>
     */
    public function locations(?string $after = null): Page;

    /**
     * Products with their variants, oldest change first.
     *
     * @return Page<array<string, mixed>>
     */
    public function products(?string $after = null, ?string $updatedSince = null): Page;

    /**
     * Available quantities of inventory items at locations.
     *
     * @param  array<int, string>  $inventoryItemIds
     * @return array<int, array{inventory_item_id: string, location_id: string, available: int, updated_at: string|null}>
     */
    public function inventoryLevels(array $inventoryItemIds): array;

    /**
     * Orders, oldest change first.
     *
     * @return Page<array<string, mixed>>
     */
    public function orders(?string $after = null, ?string $updatedSince = null, ?string $createdSince = null): Page;

    /**
     * One order, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function order(string $id): ?array;

    /**
     * Set absolute available quantities. Idempotent for the same key.
     *
     * @param  array<int, array{inventory_item_id: string, location_id: string, quantity: int}>  $quantities  at most 250
     */
    public function setInventoryQuantities(array $quantities, string $idempotencyKey): void;

    public function updateProductTitle(string $productId, string $title): void;

    /**
     * @param  array<int, array{id: string, price: string}>  $variants
     */
    public function updateVariantPrices(string $productId, array $variants): void;

    /**
     * Subscribe a topic (e.g. ORDERS_CREATE) to a callback URL. Subscribing
     * an already subscribed topic is not an error.
     */
    public function subscribeWebhook(string $topic, string $callbackUrl): void;
}
