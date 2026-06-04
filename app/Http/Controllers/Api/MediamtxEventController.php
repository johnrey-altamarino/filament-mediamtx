<?php

namespace App\Http\Controllers\Api;

use App\Services\MediamtxApi;
use App\Services\SessionEvent;
use App\Services\SessionNotifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class MediamtxEventController
{
    public function __invoke(Request $request, SessionNotifier $notifier, MediamtxApi $api): Response
    {
        $data = $request->validate([
            'event' => ['required', 'string'],
            'path' => ['required', 'string'],
            'id' => ['nullable', 'string'],
            'ip' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
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
            // Control API by session id. On "started" the session is live and the
            // lookup succeeds; cache it so the matching "ended" event (when the
            // session has already left the API) can still report the IP.
            $ip = $data['ip'] ?? '';
            if ($ip === '') {
                $ip = $api->findSessionIp($id);
            }

            if ($id !== '' && $ip !== '') {
                Cache::put("mtx:sessionip:{$id}", $ip, now()->addHours(6));
            } elseif ($id !== '') {
                $ip = (string) Cache::get("mtx:sessionip:{$id}", '');
            }

            $notifier->notify(new SessionEvent(
                kind: $kind,
                sessionId: $id,
                path: $data['path'],
                ip: $ip,
                state: $kind,
                occurredAt: now()->toIso8601String(),
                type: $data['type'] ?? '',
            ), $phase);
        }

        return response()->noContent();
    }
}
