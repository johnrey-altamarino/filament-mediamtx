<?php

namespace App\Filament\Widgets;

use App\Services\DashboardAnalytics;
use Filament\Widgets\ChartWidget;

class OsBreakdownChart extends ChartWidget
{
    protected static ?string $heading = 'OS Breakdown (Last 12 Hours)';
    protected static ?int $sort = 20;
    protected static ?string $pollingInterval = '60s';

    protected function getData(): array
{
    $data = app(DashboardAnalytics::class)->lastHours(12);

    $rows = collect($data['os']);

    return [
        'labels' => $rows->pluck('label')->all(),
        'datasets' => [[
            'label' => 'Requests',
            'data' => $rows->pluck('c')->all(),
        ]],
    ];
}

    protected function getType(): string
    {
        return 'bar';
    }
}
