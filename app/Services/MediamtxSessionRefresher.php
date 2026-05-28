<?php

namespace App\Services;

use App\Models\MediamtxSession;

class MediamtxSessionRefresher
{
    public function __construct(private readonly MediamtxApi $api) {}

    public function refresh(): SessionDiff
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
                'bytes_received' => (int) data_get($it, 'bytesReceived', 0),
                'bytes_sent' => (int) data_get($it, 'bytesSent', 0),
            ];
        })->filter(fn ($r) => $r['session_id'] !== '')->values();

        $apiIds = $rows->pluck('session_id')->all();

        // Existing rows BEFORE upsert, used for diff. Only the webrtc type matters here.
        $existing = MediamtxSession::query()
            ->where('type', 'webrtc')
            ->get(['session_id', 'state', 'path', 'ip', 'created', 'last_seen_at'])
            ->keyBy('session_id');

        $started = [];
        foreach ($rows as $row) {
            if (! $existing->has($row['session_id'])) {
                $kind = $this->stateToKind($row['state']);
                if ($kind === null) {
                    continue;
                }
                $started[] = new SessionEvent(
                    kind: $kind,
                    sessionId: $row['session_id'],
                    path: $row['path'],
                    ip: $row['ip'],
                    state: $row['state'],
                    occurredAt: $row['created'] !== '' ? $row['created'] : $now->toIso8601String(),
                );
            }
        }

        $ended = [];
        foreach ($existing as $sessionId => $old) {
            if (! in_array($sessionId, $apiIds, true)) {
                $kind = $this->stateToKind((string) $old->state);
                if ($kind === null) {
                    continue;
                }
                $ended[] = new SessionEvent(
                    kind: $kind,
                    sessionId: $sessionId,
                    path: (string) $old->path,
                    ip: (string) $old->ip,
                    state: (string) $old->state,
                    occurredAt: optional($old->last_seen_at)->toIso8601String() ?? $now->toIso8601String(),
                );
            }
        }

        foreach ($rows as $row) {
            MediamtxSession::updateOrCreate(
                ['type' => $row['type'], 'session_id' => $row['session_id']],
                $row + ['last_seen_at' => $now],
            );
        }

        $stale = (int) config('services.mediamtx.stale_seconds', 20);
        MediamtxSession::where('last_seen_at', '<', now()->subSeconds($stale))->delete();

        return new SessionDiff(started: $started, ended: $ended);
    }

    private function stateToKind(string $state): ?string
    {
        return match ($state) {
            'publish' => 'publish',
            'read' => 'read',
            default => null,
        };
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
