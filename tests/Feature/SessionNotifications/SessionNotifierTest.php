<?php

namespace Tests\Feature\SessionNotifications;

use App\Jobs\SendTelegramMessage;
use App\Services\SessionEvent;
use App\Services\SessionNotifier;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SessionNotifierTest extends TestCase
{
    private function event(string $kind, string $ip = '1.2.3.4'): SessionEvent
    {
        return new SessionEvent(
            kind: $kind,
            sessionId: 'sess-1',
            path: 'live/cam1',
            ip: $ip,
            state: $kind,
            occurredAt: '2026-06-04T10:00:00Z',
        );
    }

    public function test_publish_started_dispatches_one_telegram_job(): void
    {
        Queue::fake();

        app(SessionNotifier::class)->notify($this->event('publish'), 'started');

        Queue::assertPushed(SendTelegramMessage::class, function (SendTelegramMessage $job) {
            return str_contains($job->text, 'New publisher on')
                && str_contains($job->text, 'live/cam1');
        });
    }

    public function test_duplicate_event_within_ttl_is_suppressed(): void
    {
        Queue::fake();

        $notifier = app(SessionNotifier::class);
        $notifier->notify($this->event('publish'), 'started');
        $notifier->notify($this->event('publish'), 'started');

        Queue::assertPushed(SendTelegramMessage::class, 1);
    }

    public function test_read_flood_cap_suppresses_excess(): void
    {
        Queue::fake();

        $notifier = app(SessionNotifier::class);
        for ($i = 0; $i < 25; $i++) {
            $notifier->notify($this->event('read', "10.0.0.$i"), 'started');
        }

        // READ_FLOOD_CAP_PER_MINUTE = 20
        Queue::assertPushed(SendTelegramMessage::class, 20);
    }
}
