<?php

namespace App\Filament\Widgets;

use App\Models\ViewerRequest;
use Filament\Widgets\ChartWidget;

class DeviceBreakdownChart extends ChartWidget
{
    protected static ?string $heading = 'Device Type (Last 12 Hours)';
    protected static ?int $sort = 12;
    protected static ?string $pollingInterval = '30s';

    protected function getData(): array
    {
        $rows = ViewerRequest::query()
            ->where('requested_at', '>=', now()->subHours(12))
            ->where('allowed', true)
            ->selectRaw('device, COUNT(DISTINCT ip) as c')
            ->groupBy('device')
            ->orderByDesc('c')
            ->get();

        return [
            'labels' => $rows->pluck('device')->all(),
            'datasets' => [
                [
                    'label' => 'Unique Viewers IP',
                    'data' => $rows->pluck('c')->map(fn ($v) => (int) $v)->all(),
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
