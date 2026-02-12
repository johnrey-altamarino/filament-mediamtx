<?php

use App\Console\Commands\PruneActivityLog;
use App\Console\Commands\PruneViewerRequests;
use Illuminate\Support\Facades\Schedule;


Schedule::command(PruneViewerRequests::class)->daily();
Schedule::command(PruneActivityLog::class)->daily();
