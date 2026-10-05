<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inventoros\Plugins\Shopify\Jobs\PushInventory;
use Inventoros\Plugins\Shopify\Kit\Connections\ConnectionHealth;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Kit\Exceptions\RateLimited;
use Inventoros\Plugins\Shopify\Kit\Exceptions\TransientError;
use Inventoros\Plugins\Shopify\Kit\Jobs\RetryDecision;
use Inventoros\Plugins\Shopify\Kit\Jobs\RetryPolicy;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use Inventoros\Plugins\Shopify\Kit\Models\OutboxEntry;
use Inventoros\Plugins\Shopify\Kit\Outbox\Outbox;
use Inventoros\Plugins\Shopify\Kit\Support\CostBudget;
use Inventoros\Plugins\Shopify\Shopify\ClientFactory;
use Inventoros\Plugins\Shopify\Sync\InventoryPush;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\Fakes\FakeShopifyClient;
use Inventoros\Plugins\Shopify\Tests\TestCase;

/**
 * Rate limits (429 + Retry-After, GraphQL cost), 5xx retries with
 * backoff then dead-letter, permanent failures, and the circuit breaker,
 * through the real HTTP client against faked Shopify responses.
 */
final class RetryAndRateLimitTest extends TestCase
{
    use ShopifyWorld;

    private const ENDPOINT = 'https://acme-store.myshopify.com/admin/api/2026-07/graphql.json';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        $this->mapLocations();
        $this->mapCatalog();

        // The real client, against faked HTTP responses.
        $this->app->instance(ClientFactory::class, new ClientFactory);
        CostBudget::for($this->store->id)->reset();
        app(Outbox::class)->put($this->store, InventoryPush::KIND, 'p'.$this->bolt->id);
    }

    private function push(int $attempt = 1, int $throttles = 0): void
    {
        $job = new PushInventory($this->store->id);
        $job->attempt = $attempt;
        $job->throttles = $throttles;
        $job->handle();
    }

    public function test_a_429_waits_for_retry_after_without_using_an_attempt(): void
    {
        Queue::fake();
        Http::fake([self::ENDPOINT => Http::response(['errors' => 'Exceeded 2 calls per second'], 429, ['Retry-After' => '7.0'])]);

        $this->push();

        Queue::assertPushed(PushInventory::class, function (PushInventory $job) {
            return $job->attempt === 1 && $job->throttles === 1
                && abs(now()->diffInSeconds($job->delay, false) - 7) <= 1;
        });
        $this->assertSame(0, DeadLetter::query()->count());
        $this->assertSame(0, $this->store->fresh()->consecutive_failures, 'a rate limit is not a failure');
        $this->assertSame(1, OutboxEntry::query()->count());
    }

    public function test_after_the_wait_the_push_goes_through(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['errors' => 'Exceeded'], 429, ['Retry-After' => '1'])
            ->push(FakeShopifyClient::fixture('set_quantities'), 200)]);

        $this->push();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Shopify-Access-Token', self::TOKEN)
            && str_contains($request['query'], 'inventorySetQuantities'));
        $this->assertSame(0, OutboxEntry::query()->count());
    }

    public function test_a_throttled_graphql_response_waits_by_query_cost(): void
    {
        Queue::fake();
        Http::fake([self::ENDPOINT => Http::response(FakeShopifyClient::fixture('throttled'), 200)]);

        $this->push();

        // (302 requested - 2 available) / 100 per second = 3 seconds.
        Queue::assertPushed(PushInventory::class, fn (PushInventory $job) => $job->throttles === 1
            && abs(now()->diffInSeconds($job->delay, false) - 3) <= 1);
    }

    public function test_an_empty_cost_budget_waits_before_sending_anything(): void
    {
        Queue::fake();
        Http::fake();
        CostBudget::for($this->store->id)->observe(0, 2000, 100);

        $this->push();

        Http::assertNothingSent();
        Queue::assertPushed(PushInventory::class, fn (PushInventory $job) => $job->throttles === 1);
    }

    public function test_a_5xx_is_retried_with_backoff(): void
    {
        Queue::fake();
        Http::fake([self::ENDPOINT => Http::response('upstream error', 503)]);

        $this->push();

        Queue::assertPushed(PushInventory::class, fn (PushInventory $job) => $job->attempt === 2
            && abs(now()->diffInSeconds($job->delay, false) - 15) <= 1);
        $this->assertSame(1, $this->store->fresh()->consecutive_failures);
        $this->assertSame(0, DeadLetter::query()->count());
    }

    public function test_the_last_5xx_attempt_is_dead_lettered_and_announced(): void
    {
        Queue::fake();
        Http::fake([self::ENDPOINT => Http::response('upstream error', 503)]);
        $failed = [];
        add_action('shopify_sync_failed', function ($connection, $letter) use (&$failed) {
            $failed[] = $letter->id;
        });

        $this->push(attempt: 5);

        Queue::assertNotPushed(PushInventory::class);
        $letter = DeadLetter::query()->sole();
        $this->assertSame('push_inventory', $letter->kind);
        $this->assertSame(5, $letter->attempts);
        $this->assertStringContainsString('HTTP 503', $letter->error);
        $this->assertSame([$letter->id], $failed);
        $this->assertSame(1, OutboxEntry::query()->count(), 'the work is kept');
    }

    public function test_rejected_credentials_stop_the_connection_at_once(): void
    {
        Queue::fake();
        Http::fake([self::ENDPOINT => Http::response(['errors' => '[API] Invalid API key or access token'], 401)]);

        $this->push();

        Queue::assertNotPushed(PushInventory::class);
        $store = $this->store->fresh();
        $this->assertSame(Connection::ERROR, $store->status);
        $this->assertStringContainsString('refused the access token', $store->last_error);
        $this->assertStringNotContainsString(self::TOKEN, $store->last_error);
        $this->assertSame(1, DeadLetter::query()->count());
    }

    public function test_shopify_user_errors_are_permanent(): void
    {
        Queue::fake();
        Http::fake([self::ENDPOINT => Http::response(FakeShopifyClient::fixture('user_errors'), 200)]);

        $this->push();

        Queue::assertNotPushed(PushInventory::class);
        $this->assertStringContainsString('location could not be found', DeadLetter::query()->sole()->error);
        $this->assertSame(Connection::CONNECTED, $this->store->fresh()->status);
    }

    public function test_five_failures_in_a_row_open_the_circuit_and_a_success_or_resume_closes_it(): void
    {
        $health = app(ConnectionHealth::class);

        foreach (range(1, 5) as $i) {
            $health->recordFailure($this->store->refresh(), 'HTTP 503');
        }

        $store = $this->store->fresh();
        $this->assertTrue($store->isPaused());
        $this->assertFalse($store->canSync());
        $this->assertSame('paused', $health->state($store));

        // While paused nothing is sent; the work waits.
        Http::fake();
        $this->push();
        Http::assertNothingSent();
        $this->assertSame(1, OutboxEntry::query()->count());

        $this->actingAs($this->admin)->post(route('plg.shopify.actions.connections.resume', $store))->assertRedirect();
        $this->assertTrue($this->store->fresh()->canSync());
    }

    public function test_the_retry_policy(): void
    {
        $policy = new RetryPolicy;

        $this->assertSame(RetryDecision::RETRY, $policy->decide(new TransientError('x'), 1, 0)->action);
        $this->assertSame([15, 60, 240, 900], [$policy->delayBefore(2), $policy->delayBefore(3), $policy->delayBefore(4), $policy->delayBefore(5)]);
        $this->assertSame(RetryDecision::DEAD_LETTER, $policy->decide(new TransientError('x'), 5, 0)->action);
        $this->assertSame(RetryDecision::DEAD_LETTER, $policy->decide(new PermanentError('x'), 1, 0)->action);
        $this->assertSame(RetryDecision::RETRY, $policy->decide(new \RuntimeException('bug'), 1, 0)->action);

        $throttle = $policy->decide(new RateLimited('x', 12), 5, 3);
        $this->assertSame(RetryDecision::THROTTLE, $throttle->action, 'a rate limit on the last attempt still waits');
        $this->assertSame(12, $throttle->delay);
        $this->assertSame(900, $policy->decide(new RateLimited('x', 99999), 1, 0)->delay, 'capped');
        $this->assertSame(RetryDecision::DEAD_LETTER, $policy->decide(new RateLimited('x', 1), 1, 30)->action);
    }
}
