<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inventoros\Plugins\Shopify\Kit\Support\CostBudget;
use Inventoros\Plugins\Shopify\Shopify\HttpShopifyClient;
use Inventoros\Plugins\Shopify\Tests\TestCase;

final class FulfilmentHttpClientTest extends TestCase
{
    public function test_fulfilment_pages_keep_the_cursor_and_normalise_exact_line_ids(): void
    {
        $node = ['id' => 'gid://shopify/FulfillmentOrder/70', 'status' => 'OPEN', 'requestStatus' => 'UNSUBMITTED',
            'assignedLocation' => ['location' => ['id' => 'gid://shopify/Location/1001']],
            'lineItems' => ['nodes' => [['id' => 'gid://shopify/FulfillmentOrderLineItem/80', 'lineItem' => ['id' => 'gid://shopify/LineItem/90'], 'remainingQuantity' => 2, 'inventoryItemId' => 'gid://shopify/InventoryItem/40', 'variant' => ['inventoryItem' => ['inventoryLevel' => null]]]], 'pageInfo' => ['hasNextPage' => false]]];
        Http::fake(['*' => Http::sequence()
            ->push(['data' => ['order' => ['fulfillmentOrders' => ['nodes' => [$node], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'next-page']]]]])
            ->push(['data' => ['order' => ['fulfillmentOrders' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]])]);
        $budget = CostBudget::for(8888);
        $budget->reset();
        $budget->observe(2000, 2000, 100);
        $client = new HttpShopifyClient('acme-store.myshopify.com', 'test_token', $budget);
        $rows = $client->fulfilmentOrders('5001', '1001');
        $this->assertSame('90', $rows[0]['lines'][0]['line_id']);
        $this->assertFalse($rows[0]['lines'][0]['stocked']);
        Http::assertSent(fn ($request) => (json_decode($request->body(), true)['variables']['after'] ?? null) === 'next-page');
        Http::assertSentCount(2);
    }

    public function test_activation_is_idempotent_without_overwriting_existing_quantities_and_moves_name_partial_lines(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['data' => ['inventoryActivate' => ['inventoryLevel' => ['id' => 'level'], 'userErrors' => []]]])
            ->push(['data' => ['fulfillmentOrderMove' => ['movedFulfillmentOrder' => ['id' => '70'], 'userErrors' => []]]])
            ->push(['data' => ['fulfillmentCreate' => ['fulfillment' => ['id' => 'gid://shopify/Fulfillment/91'], 'userErrors' => []]]])]);
        $budget = CostBudget::for(8889);
        $budget->reset();
        $client = new HttpShopifyClient('acme-store.myshopify.com', 'test_token', $budget);
        $client->activateInventory('40', '1001', 'activation-key');
        $client->moveFulfilmentOrder('70', '1001', [['id' => '80', 'quantity' => 1]]);
        $this->assertSame('91', $client->createFulfilment(['lineItemsByFulfillmentOrder' => [['fulfillmentOrderId' => 'gid://shopify/FulfillmentOrder/70', 'fulfillmentOrderLineItems' => [['id' => 'gid://shopify/FulfillmentOrderLineItem/80', 'quantity' => 1]]]], 'notifyCustomer' => false], 'Shipment 1'));
        Http::assertSent(fn ($request) => (json_decode($request->body(), true)['variables']['key'] ?? null) === 'activation-key' && ! str_contains(json_decode($request->body(), true)['query'], 'available:') && str_contains(json_decode($request->body(), true)['query'], '@idempotent'));
        Http::assertSent(fn ($request) => (json_decode($request->body(), true)['variables']['lines'][0]['quantity'] ?? null) === 1);
    }

    public function test_nested_fulfilment_lines_are_complete_and_keep_the_destination_location(): void
    {
        Sleep::fake();
        $line = ['id' => 'gid://shopify/FulfillmentOrderLineItem/80', 'lineItem' => ['id' => 'gid://shopify/LineItem/90'],
            'remainingQuantity' => 2, 'inventoryItemId' => 'gid://shopify/InventoryItem/40', 'variant' => ['inventoryItem' => ['inventoryLevel' => null]]];
        $node = ['id' => 'gid://shopify/FulfillmentOrder/70', 'status' => 'OPEN', 'requestStatus' => 'UNSUBMITTED',
            'assignedLocation' => ['location' => ['id' => 'gid://shopify/Location/1001']],
            'lineItems' => ['nodes' => [$line], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'line-page']]];
        $next = array_replace($line, ['id' => 'gid://shopify/FulfillmentOrderLineItem/81']);
        $cost = ['extensions' => ['cost' => ['throttleStatus' => ['currentlyAvailable' => 2000, 'maximumAvailable' => 2000, 'restoreRate' => 100]]]];
        Http::fake(['*' => Http::sequence()
            ->push(['data' => ['order' => ['fulfillmentOrders' => ['nodes' => [$node], 'pageInfo' => ['hasNextPage' => false]]]]] + $cost)
            ->push([], 429, ['Retry-After' => 1])
            ->push(['data' => ['fulfillmentOrder' => ['lineItems' => ['nodes' => [$next], 'pageInfo' => ['hasNextPage' => false]]]]] + $cost)]);
        $rows = (new HttpShopifyClient('acme-store.myshopify.com', 'token', CostBudget::for(8800)))->fulfilmentOrders('5001', '1001');
        $this->assertSame(['80', '81'], array_column($rows[0]['lines'], 'id'));
        Http::assertSent(fn ($request) => (json_decode($request->body(), true)['variables']['after'] ?? null) === 'line-page'
            && json_decode($request->body(), true)['variables']['location'] === 'gid://shopify/Location/1001');
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(1);
    }
}
