<?php

namespace App\Services;

use App\Jobs\SendTelegramMessage;
use App\Support\UserAgentLabel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SessionNotifier
{
    private const READ_DEDUPE_TTL_SECONDS = 300;

    private const READ_FLOOD_CAP_PER_MINUTE = 20;

    public function notify(SessionEvent $event, string $phase): void
    {
        [$emoji, $title] = match ([$event->kind, $phase]) {
            ['publish', 'started'] => ['🔴', 'Publisher started'],
            ['publish', 'ended'] => ['⚪', 'Publisher stopped'],
            ['read', 'started'] => ['👁️', 'Viewer started'],
            ['read', 'ended'] => ['👋', 'Viewer left'],
            default => [null, null],
        };

        if ($title === null) {
            return;
        }

        if (! $this->passesDedupe($event, $phase)) {
            return;
        }

        if ($event->kind === 'read' && $phase === 'started' && ! $this->passesReadFloodCap()) {
            return;
        }

        // Escape operator-controlled fields: the message is sent with parse_mode=HTML,
        // so an unescaped < > & in the path/ip would make Telegram reject it (HTTP 400).
        $path = htmlspecialchars($event->path, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ip = $event->ip !== '' ? htmlspecialchars($event->ip, ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'unknown';
        $protocol = $this->protocolLabel($event->type);
        $referer = $event->referer !== '' ? htmlspecialchars($event->referer, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        // Parse the raw UA to a short label (e.g. "OBS Studio") before escaping.
        $client = $event->userAgent !== ''
            ? htmlspecialchars(UserAgentLabel::format($event->userAgent), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : '';
        $when = Carbon::parse($event->occurredAt)->timezone('Asia/Manila')->format('M d, Y h:i:s A');

        $lines = [
            "{$emoji} <b>{$title}</b>",
            '',
            "📺 <b>Stream:</b> <code>{$path}</code>",
        ];
        if ($protocol !== '') {
            $lines[] = "🔌 <b>Type:</b> {$protocol}";
        }
        $lines[] = "🌐 <b>IP:</b> <code>{$ip}</code>";
        if ($client !== '') {
            $lines[] = "💻 <b>Client:</b> {$client}";
        }
        if ($referer !== '') {
            $lines[] = "🔗 <b>Referer:</b> {$referer}";
        }
        if ($event->sessionId !== '') {
            $sessionId = htmlspecialchars($event->sessionId, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $lines[] = "🆔 <b>Session:</b> <code>{$sessionId}</code>";
        }
        $lines[] = "🕒 <i>{$when}</i>";

        SendTelegramMessage::dispatch(implode("\n", $lines));
    }

    /**
     * Map a MediaMTX source/reader type (e.g. "webRTCSession") to a friendly label.
     * Unknown values pass through unchanged; an empty value hides the line.
     */
    private function protocolLabel(string $type): string
    {
        return match ($type) {
            'webRTCSession' => 'WebRTC',
            'rtmpConn' => 'RTMP',
            'rtmpsConn' => 'RTMPS',
            'rtspSession' => 'RTSP',
            'rtspsSession' => 'RTSPS',
            'srtConn' => 'SRT',
            'hlsMuxer' => 'HLS',
            default => $type,
        };
    }

    private function passesDedupe(SessionEvent $event, string $phase): bool
    {
        // MediaMTX hooks may omit the session id (env vars vary by version). Fall back to
        // path (publish) / ip:path (read) so distinct streams/viewers don't collapse onto
        // one dedupe key and get suppressed.
        $id = $event->sessionId !== ''
            ? $event->sessionId
            : ($event->kind === 'read' ? "{$event->ip}:{$event->path}" : $event->path);

        $key = match ([$event->kind, $phase]) {
            ['publish', 'started'] => "notify:publish.started:{$id}",
            ['publish', 'ended'] => "notify:publish.ended:{$id}",
            ['read', 'started'] => "notify:read.started:{$id}",
            ['read', 'ended'] => "notify:read.ended:{$id}",
            default => null,
        };

        if ($key === null) {
            return false;
        }

        $ttl = match ($event->kind) {
            'read' => self::READ_DEDUPE_TTL_SECONDS,
            'publish' => 3600, // publish events are session-unique; 1h is enough to prevent any double-tick from rapid retries
            default => 60,
        };

        return Cache::add($key, 1, $ttl);
    }

    private function passesReadFloodCap(): bool
    {
        $minuteKey = 'notify:read:flood:'.now()->format('YmdHi');

        Cache::add($minuteKey, 0, 65);
        $count = Cache::increment($minuteKey);

        if ($count > self::READ_FLOOD_CAP_PER_MINUTE) {
            $logGate = 'notify:read:flood:logged:'.now()->format('YmdHi');
            if (Cache::add($logGate, 1, 65)) {
                Log::warning('Read-session notification flood cap reached', [
                    'minute' => now()->format('YmdHi'),
                    'cap' => self::READ_FLOOD_CAP_PER_MINUTE,
                ]);
            }

            return false;
        }

        return true;
    }
}
