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

            // MediaMTX hooks expose neither the client IP nor the User-Agent, so
            // resolve both from the Control API by session id in a single lookup.
            // On "started" the session is live and the lookup succeeds; cache them
            // so the matching "ended" event (when the session has already left the
            // API) can still report them.
            $ip = $data['ip'] ?? '';
            $userAgent = '';

            if ($id !== '') {
                $session = $api->findSession($id, $data['type'] ?? '');

                if ($ip === '') {
                    $ip = $session['ip'];
                }
                $userAgent = $session['userAgent'];

                if ($ip !== '' || $userAgent !== '') {
                    Cache::put("mtx:sessionip:{$id}", $ip, now()->addHours(6));
                    Cache::put("mtx:sessionua:{$id}", $userAgent, now()->addHours(6));
                }
                if ($ip === '') {
                    $ip = (string) Cache::get("mtx:sessionip:{$id}", '');
                }
                if ($userAgent === '') {
                    $userAgent = (string) Cache::get("mtx:sessionua:{$id}", '');
                }
            }

            // Referer is only known to /api/read (viewers); correlate it by ip+path.
            $referer = '';
            if ($kind === 'read' && $ip !== '') {
                $referer = (string) Cache::get("mtx:referer:{$ip}:{$data['path']}", '');
            }

            $notifier->notify(new SessionEvent(
                kind: $kind,
                sessionId: $id,
                path: $data['path'],
                ip: $ip,
                state: $kind,
                occurredAt: now()->toIso8601String(),
                type: $data['type'] ?? '',
                referer: $referer,
                userAgent: $userAgent,
            ), $phase);
        }

        return response()->noContent();
    }
}
