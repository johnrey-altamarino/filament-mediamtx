<?php

namespace App\Http\Controllers\Api;

use App\Services\SessionEvent;
use App\Services\SessionNotifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MediamtxEventController
{
    public function __invoke(Request $request, SessionNotifier $notifier): Response
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
            $notifier->notify(new SessionEvent(
                kind: $kind,
                sessionId: $data['id'] ?? '',
                path: $data['path'],
                ip: $data['ip'] ?? '',
                state: $kind,
                occurredAt: now()->toIso8601String(),
            ), $phase);
        }

        return response()->noContent();
    }
}
