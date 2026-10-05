<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Console;

use Illuminate\Console\Command;
use Inventoros\Plugins\Shopify\Models\Activity;

/**
 * Deletes activity lines older than --days. Scheduled daily by the plugin.
 */
final class PruneActivityCommand extends Command
{
    protected $signature = 'shopify:prune-activity {--days=30 : Keep this many days of activity}';

    protected $description = 'Delete Shopify activity older than the given number of days';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('--days must be 1 or more.');

            return self::INVALID;
        }

        $deleted = Activity::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Deleted {$deleted} activity ".($deleted === 1 ? 'line' : 'lines').'.');

        return self::SUCCESS;
    }
}
