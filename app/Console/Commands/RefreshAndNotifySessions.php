<?php

namespace App\Console\Commands;

use App\Services\MediamtxSessionRefresher;
use App\Services\SessionNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshAndNotifySessions extends Command
{
    protected $signature = 'sessions:refresh-and-notify';

    protected $description = 'Refresh MediaMTX WebRTC sessions and notify admins of started/ended sessions';

    public function handle(MediamtxSessionRefresher $refresher, SessionNotifier $notifier): int
    {
        try {
            $diff = $refresher->refresh();
            $notifier->dispatch($diff);
        } catch (\Throwable $e) {
            Log::warning('sessions:refresh-and-notify failed', ['exception' => $e]);
        }

        return self::SUCCESS;
    }
}
