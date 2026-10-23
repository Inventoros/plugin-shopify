<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Shopify;

/**
 * The GraphQL operations the plugin sends (Admin API, ShopifyClient::API_VERSION).
 *
 * Required access scopes: read_products, write_products (title and price
 * push), read_inventory, write_inventory, read_locations, read_orders.
 */
final class Queries
{
    public const SHOP = <<<'GQL'
        query Shop {
          shop { id name myshopifyDomain currencyCode plan { displayName } }
          currentAppInstallation { accessScopes { handle } }
        }
        GQL;

    public const LOCATIONS = <<<'GQL'
        query Locations($first: Int!, $after: String) {
          locations(first: $first, after: $after, includeInactive: false) {
            nodes { id name isActive }
            pageInfo { hasNextPage endCursor }
          }
        }
        GQL;

    public const PRODUCTS = <<<'GQL'
        query Products($first: Int!, $after: String, $query: String) {
          products(first: $first, after: $after, query: $query, sortKey: UPDATED_AT) {
            nodes {
              id title status updatedAt
              variants(first: 50) {
                nodes {
                  id sku title price barcode
                  selectedOptions { name value }
                  inventoryItem { id }
                }
                pageInfo { hasNextPage endCursor }
              }
            }
            pageInfo { hasNextPage endCursor }
          }
        }
        GQL;

    public const INVENTORY_LEVELS = <<<'GQL'
        query InventoryLevels($ids: [ID!]!) {
          nodes(ids: $ids) {
            ... on InventoryItem {
              id
              inventoryLevels(first: 25) {
                nodes {
                  updatedAt
                  location { id }
                  quantities(names: ["available"]) { name quantity }
                }
                pageInfo { hasNextPage endCursor }
              }
            }
          }
        }
        GQL;

    public const ORDERS = <<<'GQL'
        query Orders($first: Int!, $after: String, $query: String) {
          orders(first: $first, after: $after, query: $query, sortKey: UPDATED_AT) {
            nodes {
              id name createdAt updatedAt cancelledAt currencyCode email test note
              displayFinancialStatus displayFulfillmentStatus
              customer { firstName lastName }
              shippingAddress { address1 address2 city provinceCode zip countryCodeV2 }
              totalTaxSet { shopMoney { amount } }
              totalShippingPriceSet { shopMoney { amount } }
              totalDiscountsSet { shopMoney { amount } }
              lineItems(first: 50) {
                nodes {
                  id sku name quantity
                  variant { id }
                  product { id }
                  originalUnitPriceSet { shopMoney { amount } }
                  totalDiscountSet { shopMoney { amount } }
                }
                pageInfo { hasNextPage endCursor }
              }
            }
            pageInfo { hasNextPage endCursor }
          }
        }
        GQL;

    public const ORDER = <<<'GQL'
        query Order($id: ID!) {
          order(id: $id) {
              id name createdAt updatedAt cancelledAt currencyCode email test note
              displayFinancialStatus displayFulfillmentStatus
              customer { firstName lastName }
              shippingAddress { address1 address2 city provinceCode zip countryCodeV2 }
              totalTaxSet { shopMoney { amount } }
              totalShippingPriceSet { shopMoney { amount } }
              totalDiscountsSet { shopMoney { amount } }
              lineItems(first: 50) {
                nodes {
                  id sku name quantity
                  variant { id }
                  product { id }
                  originalUnitPriceSet { shopMoney { amount } }
                  totalDiscountSet { shopMoney { amount } }
                }
                pageInfo { hasNextPage endCursor }
              }
          }
        }
        GQL;

    public const PRODUCT_VARIANTS = <<<'GQL'
        query ProductVariants($id: ID!, $after: String) {
          product(id: $id) {
            id
            variants(first: 100, after: $after) {
              nodes { id sku title price barcode selectedOptions { name value } inventoryItem { id } }
              pageInfo { hasNextPage endCursor }
            }
          }
        }
        GQL;

    public const ORDER_LINES = <<<'GQL'
        query OrderLines($id: ID!, $after: String) {
          order(id: $id) {
            id
            lineItems(first: 100, after: $after) {
              nodes {
                id sku name quantity variant { id } product { id }
                originalUnitPriceSet { shopMoney { amount } }
                totalDiscountSet { shopMoney { amount } }
              }
              pageInfo { hasNextPage endCursor }
            }
          }
        }
        GQL;

    public const INVENTORY_ITEM_LEVELS = <<<'GQL'
        query InventoryItemLevels($id: ID!, $after: String) {
          inventoryItem(id: $id) {
            id
            inventoryLevels(first: 100, after: $after) {
              nodes { updatedAt location { id } quantities(names: ["available"]) { name quantity } }
              pageInfo { hasNextPage endCursor }
            }
          }
        }
        GQL;

    public const ORDER_TRANSACTIONS = <<<'GQL'
        query OrderTransactions($id: ID!) {
          order(id: $id) {
            transactionsCount { count precision }
            transactions(first: 100) {
              id kind status gateway processedAt createdAt parentTransaction { id }
              amountSet { shopMoney { amount currencyCode } }
            }
          }
        }
        GQL;

    public const SET_QUANTITIES = <<<'GQL'
        mutation SetQuantities($input: InventorySetQuantitiesInput!, $idempotencyKey: String!) {
          inventorySetQuantities(input: $input) @idempotent(key: $idempotencyKey) {
            inventoryAdjustmentGroup { id }
            userErrors { field message code }
          }
        }
        GQL;

    public const UPDATE_PRODUCT = <<<'GQL'
        mutation UpdateProduct($product: ProductUpdateInput!) {
          productUpdate(product: $product) {
            product { id }
            userErrors { field message }
          }
        }
        GQL;

    public const UPDATE_VARIANTS = <<<'GQL'
        mutation UpdateVariants($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
          productVariantsBulkUpdate(productId: $productId, variants: $variants) {
            productVariants { id }
            userErrors { field message }
          }
        }
        GQL;

    public const SUBSCRIBE_WEBHOOK = <<<'GQL'
        mutation Subscribe($topic: WebhookSubscriptionTopic!, $subscription: WebhookSubscriptionInput!) {
          webhookSubscriptionCreate(topic: $topic, webhookSubscription: $subscription) {
            webhookSubscription { id }
            userErrors { field message }
          }
        }
        GQL;
}
