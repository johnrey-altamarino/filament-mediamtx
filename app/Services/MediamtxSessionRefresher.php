<?php

namespace App\Services;

use App\Models\MediamtxSession;

class MediamtxSessionRefresher
{
    public function __construct(private readonly MediamtxApi $api) {}

    public function syncSnapshot(): void
    {
        $now = now();

        $items = data_get($this->api->listWebRtcSessions(), 'items', []);

        $rows = collect($items)->map(function ($it) {
            $remote = (string) data_get($it, 'remoteAddr', '');

            return [
                'type' => 'webrtc',
                'session_id' => (string) data_get($it, 'id', ''),
                'state' => (string) data_get($it, 'state', ''),
                'path' => (string) data_get($it, 'path', ''),
                'query' => (string) data_get($it, 'query', ''),
                'remote_addr' => $remote,
                'ip' => $this->extractIp($remote),
                'created' => (string) data_get($it, 'created', ''),
            ];
        })->filter(fn ($r) => $r['session_id'] !== '')->values();

        foreach ($rows as $row) {
            MediamtxSession::updateOrCreate(
                ['type' => $row['type'], 'session_id' => $row['session_id']],
                $row + ['last_seen_at' => $now],
            );
        }

        $stale = (int) config('services.mediamtx.stale_seconds', 20);
        MediamtxSession::where('last_seen_at', '<', now()->subSeconds($stale))->delete();
    }

    private function extractIp(string $remoteAddr): string
    {
        if (str_starts_with($remoteAddr, '[')) {
            $end = strpos($remoteAddr, ']');

            return $end !== false ? substr($remoteAddr, 1, $end - 1) : $remoteAddr;
        }

        if (substr_count($remoteAddr, ':') === 1) {
            return explode(':', $remoteAddr, 2)[0];
        }

        return $remoteAddr;
    }
}
