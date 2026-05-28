<?php

use App\Console\Commands\PruneActivityLog;
use App\Console\Commands\PruneViewerRequests;
use App\Console\Commands\RefreshAndNotifySessions;
use Illuminate\Support\Facades\Schedule;

Schedule::command(PruneViewerRequests::class)->daily();
Schedule::command(PruneActivityLog::class)->daily();

Schedule::command(RefreshAndNotifySessions::class)
    ->everyTenSeconds()
    ->withoutOverlapping()
    ->runInBackground();
