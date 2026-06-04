<?php

namespace App\Http\Controllers\Api;

use App\Services\MediamtxApi;
use App\Services\SessionEvent;
use App\Services\SessionNotifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MediamtxEventController
{
    public function __invoke(Request $request, SessionNotifier $notifier, MediamtxApi $api): Response
    {
        $data = $request->validate([
            'event' => ['required', 'string'],
            'path' => ['required', 'string'],
            'id' => ['nullable', 'string'],
            'ip' => ['nullable', 'string'],
        ]);

        [$kind, $phase] = match ($data['event']) {
            'ready' => ['publish', 'started'],
            'notready' => ['publish', 'ended'],
            'read' => ['read', 'started'],
            'unread' => ['read', 'ended'],
            default => [null, null],
        };

        if ($kind !== null) {
            $id = $data['id'] ?? '';

            // MediaMTX hooks don't expose the client IP, so resolve it from the
            // Control API by session id. Best-effort: empty on miss/ended/API-down.
            $ip = $data['ip'] ?? '';
            if ($ip === '') {
                $ip = $api->findSessionIp($id);
            }

            $notifier->notify(new SessionEvent(
                kind: $kind,
                sessionId: $id,
                path: $data['path'],
                ip: $ip,
                state: $kind,
                occurredAt: now()->toIso8601String(),
            ), $phase);
        }

        return response()->noContent();
    }
}
