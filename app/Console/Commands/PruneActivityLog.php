<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

class PruneActivityLog extends Command
{
    protected $signature = 'activity-log:prune {--days=30}';

    protected $description = 'Delete activity_log entries older than N days';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $deleted = Activity::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Deleted {$deleted} activity_log rows older than {$days} days.");

        return self::SUCCESS;
    }
}
