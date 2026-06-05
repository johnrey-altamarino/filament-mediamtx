<?php

namespace App\Filament\Widgets;

use App\Services\DashboardAnalytics;
use Filament\Widgets\ChartWidget;

class DeviceBreakdownChart extends ChartWidget
{
    protected ?string $heading = 'Device Breakdown (Last 12 Hours)';

    protected static ?int $sort = 22;

    protected ?string $pollingInterval = '60s';

    protected function getData(): array
    {
        $data = app(DashboardAnalytics::class)->lastHours(12);

        $rows = collect($data['device']);

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
