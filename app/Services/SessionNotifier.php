<?php

namespace App\Services;

use App\Filament\Pages\Sessions;
use App\Models\User;
use Carbon\Carbon;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SessionNotifier
{
    private const READ_DEDUPE_TTL_SECONDS = 300;

    private const READ_FLOOD_CAP_PER_MINUTE = 20;

    public function dispatch(SessionDiff $diff): void
    {
        $recipients = $this->recipients();
        if ($recipients->isEmpty()) {
            return;
        }

        foreach ($diff->started as $event) {
            $this->maybeSend($event, 'started', $recipients);
        }

        foreach ($diff->ended as $event) {
            $this->maybeSend($event, 'ended', $recipients);
        }
    }

    private function maybeSend(SessionEvent $event, string $phase, Collection $recipients): void
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

        $color = $phase === 'started' ? 'success' : 'gray';

        Notification::make()
            ->title("{$titleVerb} {$event->path}")
            ->body("IP {$event->ip} · ".Carbon::parse($event->occurredAt)->diffForHumans())
            ->icon('heroicon-o-signal')
            ->color($color)
            ->actions([
                Action::make('view_sessions')
                    ->label('View sessions')
                    ->url(Sessions::getUrl(), shouldOpenInNewTab: false)
                    ->markAsRead(),
            ])
            ->sendToDatabase($recipients);
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

    private function recipients(): Collection
    {
        return Cache::remember(
            'notify:recipients:admins',
            60,
            fn () => User::role(['super-admin', 'moderator'])->get(),
        );
    }
}
