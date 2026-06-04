<?php

namespace App\Services;

use App\Jobs\SendTelegramMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SessionNotifier
{
    private const READ_DEDUPE_TTL_SECONDS = 300;

    private const READ_FLOOD_CAP_PER_MINUTE = 20;

    public function notify(SessionEvent $event, string $phase): void
    {
        $titleVerb = match ([$event->kind, $phase]) {
            ['publish', 'started'] => 'New publisher on',
            ['publish', 'ended'] => 'Publisher ended on',
            ['read', 'started'] => 'New viewer on',
            ['read', 'ended'] => 'Viewer ended on',
            default => null,
        };

        if ($titleVerb === null) {
            return;
        }

        if (! $this->passesDedupe($event, $phase)) {
            return;
        }

        if ($event->kind === 'read' && $phase === 'started' && ! $this->passesReadFloodCap()) {
            return;
        }

        $when = Carbon::parse($event->occurredAt)->diffForHumans();
        $ip = $event->ip !== '' ? $event->ip : 'unknown';

        $text = "<b>{$titleVerb} {$event->path}</b>\nIP {$ip} · {$when}";

        SendTelegramMessage::dispatch($text);
    }

    private function passesDedupe(SessionEvent $event, string $phase): bool
    {
        $key = match ([$event->kind, $phase]) {
            ['publish', 'started'] => "notify:publish.started:{$event->sessionId}",
            ['publish', 'ended'] => "notify:publish.ended:{$event->sessionId}",
            ['read', 'started'] => "notify:read.started:{$event->ip}:{$event->path}",
            ['read', 'ended'] => "notify:read.ended:{$event->ip}:{$event->path}",
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
