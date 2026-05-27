<?php

namespace App\Services;

use App\Models\MediamtxSession;

class MediamtxSessionRefresher
{
    public function refresh(): void
    {
        $api = app(MediamtxApi::class);
        $now = now();

        $items = data_get($api->listWebRtcSessions(), 'items', []);

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
                'bytes_received' => (int) data_get($it, 'bytesReceived', 0),
                'bytes_sent' => (int) data_get($it, 'bytesSent', 0),
            ];
        })->filter(fn ($r) => $r['session_id'] !== '');

        foreach ($rows as $row) {
            MediamtxSession::updateOrCreate(
                ['type' => $row['type'], 'session_id' => $row['session_id']],
                $row + ['last_seen_at' => $now],
            );
        }

        // delete stale rows not seen for 20s
        MediamtxSession::where('last_seen_at', '<', now()->subSeconds(20))->delete();
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
