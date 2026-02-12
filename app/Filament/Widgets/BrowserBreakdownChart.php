<?php

namespace App\Filament\Widgets;

use App\Models\ViewerRequest;
use Filament\Widgets\ChartWidget;

class BrowserBreakdownChart extends ChartWidget
{
    protected static ?string $heading = 'Browser Breakdown (Last 12 Hours)';
    protected static ?int $sort = 11;
    protected static ?string $pollingInterval = '30s';

    protected function getData(): array
    {
        $rows = ViewerRequest::query()
            ->where('requested_at', '>=', now()->subHours(12))
            ->where('allowed', true)
            ->selectRaw('browser, COUNT(DISTINCT ip) as c')
            ->groupBy('browser')
            ->orderByDesc('c')
            ->limit(8)
            ->get();

        return [
            'labels' => $rows->pluck('browser')->all(),
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
