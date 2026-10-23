<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Shopify;

final class FulfilmentQueries
{
    public const ORDERS = <<<'GQL'
        query FulfilmentOrders($id: ID!, $location: ID!, $after: String) {
          order(id: $id) {
            fulfillmentOrders(first: 2, after: $after) {
              nodes {
                id status requestStatus assignedLocation { location { id } }
                lineItems(first: 50) {
                  nodes {
                    id remainingQuantity inventoryItemId
                    lineItem { id }
                    variant { inventoryItem { inventoryLevel(locationId: $location) { id } } }
                  }
                  pageInfo { hasNextPage endCursor }
                }
              }
              pageInfo { hasNextPage endCursor }
            }
          }
        }
        GQL;

    public const LINES = <<<'GQL'
        query FulfilmentLines($id: ID!, $location: ID!, $after: String) {
          fulfillmentOrder(id: $id) {
            id
            lineItems(first: 100, after: $after) {
              nodes {
                id remainingQuantity inventoryItemId lineItem { id }
                variant { inventoryItem { inventoryLevel(locationId: $location) { id } } }
              }
              pageInfo { hasNextPage endCursor }
            }
          }
        }
        GQL;

    public const ACTIVATE = <<<'GQL'
        mutation ActivateForShipment($item: ID!, $location: ID!, $key: String!) {
          inventoryActivate(inventoryItemId: $item, locationId: $location) @idempotent(key: $key) {
            inventoryLevel { id }
            userErrors { field message }
          }
        }
        GQL;

    public const MOVE = <<<'GQL'
        mutation MoveShipmentLines($id: ID!, $location: ID!, $lines: [FulfillmentOrderLineItemInput!]!) {
          fulfillmentOrderMove(id: $id, newLocationId: $location, fulfillmentOrderLineItems: $lines) {
            movedFulfillmentOrder { id }
            userErrors { field message }
          }
        }
        GQL;

    public const CREATE = <<<'GQL'
        mutation FulfilShipment($fulfillment: FulfillmentInput!, $message: String) {
          fulfillmentCreate(fulfillment: $fulfillment, message: $message) {
            fulfillment { id }
            userErrors { field message }
          }
        }
        GQL;
}
