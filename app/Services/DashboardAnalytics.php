<?php

namespace App\Services;

use App\Models\ViewerRequest;
use App\Support\DashboardCache;

class DashboardAnalytics
{
    public function lastHours(int $hours = 12): array
    {
        return DashboardCache::remember(
            DashboardCache::key('analytics', ['hours' => $hours]),
            60,
            function () use ($hours) {
                $since = now()->subHours($hours);

                $base = ViewerRequest::query()
                    ->where('requested_at', '>=', $since)
                    ->where('allowed', true);

                $os = (clone $base)
                    ->selectRaw('COALESCE(NULLIF(os, ""), "Unknown") as label, COUNT(*) as c')
                    ->groupBy('label')
                    ->orderByDesc('c')
                    ->limit(12)
                    ->get()
                    ->map(fn ($r) => ['label' => $r->label, 'c' => (int) $r->c])
                    ->all();

                $browser = (clone $base)
                    ->selectRaw('COALESCE(NULLIF(browser, ""), "Unknown") as label, COUNT(*) as c')
                    ->groupBy('label')
                    ->orderByDesc('c')
                    ->limit(12)
                    ->get()
                    ->map(fn ($r) => ['label' => $r->label, 'c' => (int) $r->c])
                    ->all();

                $device = (clone $base)
                    ->selectRaw('COALESCE(NULLIF(device, ""), "Unknown") as label, COUNT(*) as c')
                    ->groupBy('label')
                    ->orderByDesc('c')
                    ->limit(12)
                    ->get()
                    ->map(fn ($r) => ['label' => $r->label, 'c' => (int) $r->c])
                    ->all();

                return compact('os', 'browser', 'device');
            }
        );
    }
}
