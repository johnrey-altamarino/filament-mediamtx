<?php

namespace App\Console\Commands;

use App\Models\ViewerRequest;
use Illuminate\Console\Command;

class PruneViewerRequests extends Command
{
    protected $signature = 'viewer-requests:prune {--days=7}';
    protected $description = 'Delete viewer_requests older than N days';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $deleted = ViewerRequest::query()
            ->where('requested_at', '<', $cutoff)
            ->delete();

        $this->info("Deleted {$deleted} viewer_requests older than {$days} days.");
        return self::SUCCESS;
    }
}
