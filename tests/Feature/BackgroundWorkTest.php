<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use App\Models\Inventory\Product;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Inventoros\Plugins\Shopify\Jobs\RecordActivity;
use Inventoros\Plugins\Shopify\Models\Activity;
use Inventoros\Plugins\Shopify\Support\Settings;
use Inventoros\Plugins\Shopify\Tests\TestCase;

final class BackgroundWorkTest extends TestCase
{
    public function test_low_stock_alerts_queue_a_job_only_when_enabled(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['organization_id' => $this->organization->id, 'sku' => 'LOW-'.bin2hex(random_bytes(3))]);

        do_action('low_stock_alert', $product);
        Queue::assertNothingPushed();

        Settings::for($this->organization->id)->put(['log_low_stock' => true]);
        do_action('low_stock_alert', $product);

        Queue::assertPushed(RecordActivity::class, fn (RecordActivity $job) => $job->type === 'low_stock'
            && $job->message === $product->sku
            && $job->organizationId === $this->organization->id);
    }

    public function test_the_job_writes_an_activity_line(): void
    {
        RecordActivity::dispatchSync($this->organization->id, 'low_stock', 'SKU-9');

        $this->assertDatabaseHas('plg_shopify_activity', [
            'organization_id' => $this->organization->id,
            'type' => 'low_stock',
            'message' => 'SKU-9',
        ]);
    }

    public function test_the_prune_command_deletes_only_old_lines(): void
    {
        $old = Activity::query()->create(['organization_id' => $this->organization->id, 'type' => 'note', 'message' => 'old']);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();
        Activity::query()->create(['organization_id' => $this->organization->id, 'type' => 'note', 'message' => 'new']);

        $this->artisan('shopify:prune-activity', ['--days' => 30])
            ->expectsOutput('Deleted 1 activity line.')
            ->assertSuccessful();

        $this->assertSame(['new'], Activity::query()->pluck('message')->all());
    }

    public function test_the_prune_command_rejects_a_bad_window(): void
    {
        $this->artisan('shopify:prune-activity', ['--days' => 0])->assertFailed();
    }

    public function test_the_prune_command_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'shopify:prune-activity'));

        $this->assertCount(1, $events);
        $this->assertSame('10 4 * * *', $events->first()->expression);
    }
}
