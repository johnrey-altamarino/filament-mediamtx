<?php

namespace App\Services;

use App\Models\MediamtxSession;
use Illuminate\Support\Facades\Log;

class MediamtxSessionRefresher
{
    public function __construct(private readonly MediamtxApi $api) {}

    public function syncSnapshot(): void
    {
        $now = now();

        $sources = [
            'webrtc' => fn () => $this->api->listWebRtcSessions(),
            'moq' => fn () => $this->api->listMoqSessions(),
            'rtmp' => fn () => $this->api->listRtmpConns(),
            'rtsp' => fn () => $this->api->listRtspSessions(),
            'srt' => fn () => $this->api->listSrtConns(),
        ];

        foreach ($sources as $type => $fetch) {
            try {
                $items = data_get($fetch(), 'items', []);
            } catch (\Throwable $e) {
                // One protocol being unreachable must not blank the whole page.
                Log::warning('Session snapshot fetch failed', [
                    'type' => $type, 'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($items as $it) {
                $sessionId = (string) data_get($it, 'id', '');
                if ($sessionId === '') {
                    continue;
                }

                MediamtxSession::updateOrCreate(
                    ['type' => $type, 'session_id' => $sessionId],
                    [
                        'state' => (string) data_get($it, 'state', ''),
                        'path' => (string) data_get($it, 'path', ''),
                        'query' => (string) data_get($it, 'query', ''),
                        'remote_addr' => (string) data_get($it, 'remoteAddr', ''),
                        'ip' => MediamtxApi::clientIp($it),
                        'created' => (string) data_get($it, 'created', ''),
                        'user_agent' => (string) data_get($it, 'userAgent', '') ?: null,
                        'last_seen_at' => $now,
                    ],
                );
            }
        }

        $stale = (int) config('services.mediamtx.stale_seconds', 20);
        MediamtxSession::where('last_seen_at', '<', now()->subSeconds($stale))->delete();
    }
}
