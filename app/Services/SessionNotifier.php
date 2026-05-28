<?php

namespace App\Services;

use App\Models\User;
use Filament\Notifications\Notification;

class SessionNotifier
{
    public function dispatch(SessionDiff $diff): void
    {
        $recipients = $this->recipients();
        if ($recipients->isEmpty()) {
            return;
        }

        foreach ($diff->started as $event) {
            $this->sendForEvent($event, 'started', $recipients);
        }

        foreach ($diff->ended as $event) {
            $this->sendForEvent($event, 'ended', $recipients);
        }
    }

    private function sendForEvent(SessionEvent $event, string $phase, \Illuminate\Support\Collection $recipients): void
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

        $color = $phase === 'started' ? 'success' : 'gray';

        Notification::make()
            ->title("{$titleVerb} {$event->path}")
            ->body("IP {$event->ip}")
            ->icon('heroicon-o-signal')
            ->color($color)
            ->sendToDatabase($recipients);
    }

    private function recipients(): \Illuminate\Support\Collection
    {
        return User::role(['super-admin', 'moderator'])->get();
    }
}
