<?php

namespace App\Filament\Widgets;

use App\Models\MediamtxSession;
use App\Services\MediamtxSessionRefresher;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SessionsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        // Refresh snapshot before counting — runs only while the dashboard is open.
        app(MediamtxSessionRefresher::class)->syncSnapshot();

        $total = MediamtxSession::query()->count();

        $viewers = MediamtxSession::query()
            ->where('state', 'read')
            ->count();

        $publishers = MediamtxSession::query()
            ->where('state', 'publish')
            ->count();

        return [
            Stat::make('Total Connected', $total)
                ->description('All active sessions')
                ->color('primary'),

            Stat::make('Concurrent Viewers', $viewers)
                ->description('State: read')
                ->color('success'),

            Stat::make('Concurrent Publishers', $publishers)
                ->description('State: publish')
                ->color('warning'),
        ];
    }
}
