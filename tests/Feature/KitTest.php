<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use Inventoros\Plugins\Shopify\Kit\Exceptions\RateLimited;
use Inventoros\Plugins\Shopify\Kit\Mapping\MappingConflict;
use Inventoros\Plugins\Shopify\Kit\Mapping\MappingStore;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use Inventoros\Plugins\Shopify\Kit\Models\WebhookInboxEntry;
use Inventoros\Plugins\Shopify\Kit\Jobs\DeadLetterQueue;
use Inventoros\Plugins\Shopify\Kit\Support\CostBudget;
use Inventoros\Plugins\Shopify\Kit\Support\RetryAfter;
use Inventoros\Plugins\Shopify\Kit\Sync\CursorStore;
use Inventoros\Plugins\Shopify\Kit\Sync\SyncRecorder;
use Inventoros\Plugins\Shopify\Kit\Webhooks\HmacSignature;
use Inventoros\Plugins\Shopify\Kit\Webhooks\WebhookInbox;
use Inventoros\Plugins\Shopify\Shopify\ShopDomain;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\TestCase;
use InvalidArgumentException;

/**
 * The integration kit's building blocks on their own, as the next
 * integration plugins will use them.
 */
final class KitTest extends TestCase
{
    use ShopifyWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function test_an_inbox_entry_can_be_claimed_exactly_once(): void
    {
        $inbox = app(WebhookInbox::class);
        [$entry] = $inbox->accept($this->store, 'orders/create', 'evt-1', '{"id":1}');
        $copy = WebhookInboxEntry::query()->find($entry->id);

        $this->assertTrue($inbox->claim($entry));
        $this->assertFalse($inbox->claim($copy), 'a second worker gets nothing');

        $inbox->processed($entry);
        $this->assertFalse($inbox->claim($entry->refresh()), 'a processed entry is never claimed again');
        $this->assertSame(1, $entry->attempts);
    }

    public function test_an_abandoned_claim_can_be_taken_over(): void
    {
        $inbox = app(WebhookInbox::class);
        [$entry] = $inbox->accept($this->store, 'orders/create', 'evt-2', '{"id":2}');
        $inbox->claim($entry);
        WebhookInboxEntry::query()->whereKey($entry->id)->update(['updated_at' => now()->subMinutes(20)]);

        $this->assertTrue($inbox->claim($entry->refresh()));
    }

    public function test_the_same_event_id_is_a_duplicate_even_with_another_body(): void
    {
        $inbox = app(WebhookInbox::class);
        [, $first] = $inbox->accept($this->store, 'orders/create', 'evt-3', '{"id":3}');
        [, $second] = $inbox->accept($this->store, 'orders/create', 'evt-3', '{"id":3,"x":1}');

        $this->assertFalse($first);
        $this->assertTrue($second);
    }

    public function test_hmac_signatures(): void
    {
        $signature = HmacSignature::sign('body', 'secret');

        $this->assertTrue(HmacSignature::matches('body', 'secret', $signature));
        $this->assertFalse(HmacSignature::matches('body ', 'secret', $signature));
        $this->assertFalse(HmacSignature::matches('body', 'other', $signature));
        $this->assertFalse(HmacSignature::matches('body', null, $signature));
        $this->assertFalse(HmacSignature::matches('body', 'secret', null));
        $this->assertTrue(HmacSignature::matches('body', 'secret', HmacSignature::sign('body', 'secret', HmacSignature::HEX), HmacSignature::HEX));
    }

    public function test_mappings_are_unique_both_ways(): void
    {
        $store = app(MappingStore::class);
        $a = $store->remember($this->store, 'variant', '1', ['sku' => 'A']);
        $b = $store->remember($this->store, 'variant', '2', ['sku' => 'B']);

        $store->link($a, 'product', $this->bolt->id);

        $this->expectException(MappingConflict::class);
        $store->link($b, 'product', $this->bolt->id);
    }

    public function test_out_of_order_changes_are_recognised(): void
    {
        $store = app(MappingStore::class);
        $mapping = $store->remember($this->store, 'order', '9');
        $store->touch($mapping, now());

        $this->assertTrue($store->isStale($mapping, now()->subMinute()));
        $this->assertFalse($store->isStale($mapping, now()->addMinute()));

        $store->touch($mapping, now()->subHour());
        $this->assertTrue($store->isStale($mapping->refresh(), now()->subMinute()), 'touch never moves backwards');
    }

    public function test_cursors_never_move_backwards(): void
    {
        $cursors = app(CursorStore::class);
        $cursors->advance($this->store, 'orders', 'a', now());
        $cursors->advance($this->store, 'orders', 'b', now()->subDay());

        $this->assertSame('b', $cursors->get($this->store, 'orders')->cursor);
        $this->assertTrue($cursors->position($this->store, 'orders')->gt(now()->subHour()));
    }

    public function test_the_cost_budget_leaks_and_refills(): void
    {
        $budget = CostBudget::for(99, 100, 10);
        $budget->reset();

        $budget->reserve(80);
        $this->assertSame(0, $budget->waitFor(20));

        try {
            $budget->reserve(50);
            $this->fail('expected a rate limit');
        } catch (RateLimited $limited) {
            $this->assertSame(3, $limited->retryAfter, '(50 - 20) / 10 per second');
        }
    }

    public function test_retry_after_headers(): void
    {
        $this->assertSame(2, RetryAfter::seconds('2.0'));
        $this->assertSame(1, RetryAfter::seconds('0.4'));
        $this->assertSame(5, RetryAfter::seconds(null, 5));
        $this->assertEqualsWithDelta(30, RetryAfter::seconds(now()->addSeconds(30)->toRfc7231String()), 2);
    }

    public function test_history_never_holds_credentials(): void
    {
        $log = app(SyncRecorder::class)->log($this->store, null, 'error', 'Failed with token '.self::TOKEN.' and X-Shopify-Access-Token: abc123', [
            'context' => ['header' => self::SECRET],
        ]);

        $this->assertStringNotContainsString(self::TOKEN, $log->message);
        $this->assertStringNotContainsString('abc123', $log->message);
        $this->assertStringNotContainsString(self::SECRET, json_encode($log->context));
    }

    public function test_a_dead_letter_only_rebuilds_this_plugins_jobs_for_its_own_connection(): void
    {
        $queue = app(DeadLetterQueue::class);
        $base = ['organization_id' => $this->organization->id, 'connection_id' => $this->store->id, 'kind' => 'x', 'error' => 'e', 'failed_at' => now()];

        foreach ([
            ['job' => \Illuminate\Foundation\Bus\PendingDispatch::class, 'args' => []],
            ['job' => \Inventoros\Plugins\Shopify\Jobs\PushInventory::class, 'args' => [$this->store->id + 1000]],
            ['job' => \Inventoros\Plugins\Shopify\Jobs\PushInventory::class, 'args' => 'nope'],
        ] as $payload) {
            try {
                $queue->rebuild(DeadLetter::create($base + ['payload' => $payload]));
                $this->fail('expected a refusal');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_shop_domains(): void
    {
        $this->assertSame('acme.myshopify.com', ShopDomain::normalize(' https://ACME.myshopify.com/admin/products '));
        $this->assertSame('acme.myshopify.com', ShopDomain::normalize('acme'));
        $this->assertTrue(ShopDomain::valid('acme-2.myshopify.com'));
        $this->assertFalse(ShopDomain::valid('acme.myshopify.com.evil.io'));
        $this->assertFalse(ShopDomain::valid('localhost'));
        $this->assertFalse(ShopDomain::valid('-acme.myshopify.com'));
    }
}
