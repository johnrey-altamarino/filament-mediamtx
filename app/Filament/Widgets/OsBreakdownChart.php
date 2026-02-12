<?php

namespace App\Filament\Widgets;

use App\Models\ViewerRequest;
use Filament\Widgets\ChartWidget;

class OsBreakdownChart extends ChartWidget
{
    protected static ?string $heading = 'OS Breakdown (Last 12 Hours)';
    protected static ?int $sort = 10;
    protected static ?string $pollingInterval = '30s';

    protected function getData(): array
    {
        $rows = ViewerRequest::query()
            ->where('requested_at', '>=', now()->subHours(12))
            ->where('allowed', true)
            ->selectRaw('os, COUNT(DISTINCT ip) as c')
            ->groupBy('os')
            ->orderByDesc('c')
            ->limit(8)
            ->get();

        return [
            'labels' => $rows->pluck('os')->all(),
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
