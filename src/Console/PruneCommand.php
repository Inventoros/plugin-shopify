<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Console;

use Illuminate\Console\Command;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use Inventoros\Plugins\Shopify\Kit\Models\SyncLog;
use Inventoros\Plugins\Shopify\Kit\Models\SyncRun;
use Inventoros\Plugins\Shopify\Kit\Models\WebhookInboxEntry;

/**
 * shopify:prune, daily: removes sync history older than --days (30),
 * processed and ignored webhook payloads older than 7 days, and resolved
 * failed items older than --days. Open failed items, mappings and cursors
 * are never pruned; core records are never touched.
 */
final class PruneCommand extends Command
{
    protected $signature = 'shopify:prune {--days=30 : Keep this many days of history}';

    protected $description = 'Delete old Shopify sync history and processed webhook payloads';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $logs = SyncLog::query()->where('created_at', '<', $cutoff)->delete();
        $runs = SyncRun::query()->where('created_at', '<', $cutoff)->whereNotIn('status', [SyncRun::QUEUED, SyncRun::RUNNING])->delete();
        $inbox = WebhookInboxEntry::query()
            ->whereIn('status', [WebhookInboxEntry::PROCESSED, WebhookInboxEntry::IGNORED])
            ->where('updated_at', '<', now()->subDays(min($days, 7)))
            ->delete();
        $letters = DeadLetter::query()->whereNotNull('resolved_at')->where('resolved_at', '<', $cutoff)->delete();

        $this->info("Pruned {$logs} log line(s), {$runs} run(s), {$inbox} webhook payload(s), {$letters} resolved failed item(s).");

        return self::SUCCESS;
    }
}
