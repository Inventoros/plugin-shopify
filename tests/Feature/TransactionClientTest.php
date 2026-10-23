<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Kit\Support\CostBudget;
use Inventoros\Plugins\Shopify\Shopify\HttpShopifyClient;
use Inventoros\Plugins\Shopify\Tests\TestCase;

final class TransactionClientTest extends TestCase
{
    public function test_transactions_keep_exact_amounts_currency_dates_and_parent_references(): void
    {
        Http::fake(['*' => Http::response(['data' => ['order' => [
            'transactionsCount' => ['count' => 1, 'precision' => 'EXACT'],
            'transactions' => [['id' => 'gid://shopify/OrderTransaction/123', 'kind' => 'REFUND', 'status' => 'SUCCESS',
                'gateway' => 'example', 'createdAt' => '2026-10-02T10:00:00Z', 'parentTransaction' => ['id' => 'gid://shopify/OrderTransaction/122'],
                'amountSet' => ['shopMoney' => ['amount' => '9007199254.12', 'currencyCode' => 'USD']]]],
        ]]])]);
        $client = new HttpShopifyClient('acme-store.myshopify.com', 'token', CostBudget::for(123));
        $row = $client->transactions('5001')[0];
        $this->assertSame('9007199254.12', $row['amount']);
        $this->assertSame('USD', $row['currency']);
        $this->assertSame('refund', $row['kind']);
        $this->assertSame('122', $row['parent_id']);
        $this->assertSame('2026-10-02T10:00:00Z', $row['processed_at']);
    }

    public function test_incomplete_transaction_lists_are_refused(): void
    {
        Http::fake(['*' => Http::response(['data' => ['order' => [
            'transactionsCount' => ['count' => 101, 'precision' => 'EXACT'], 'transactions' => [],
        ]]])]);
        $this->expectException(PermanentError::class);
        $this->expectExceptionMessage('complete transaction history');
        (new HttpShopifyClient('acme-store.myshopify.com', 'token', CostBudget::for(123)))->transactions('5001');
    }
}
