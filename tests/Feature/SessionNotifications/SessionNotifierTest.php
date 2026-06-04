<?php

namespace Tests\Feature\SessionNotifications;

use App\Jobs\SendTelegramMessage;
use App\Services\SessionEvent;
use App\Services\SessionNotifier;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SessionNotifierTest extends TestCase
{
    private function event(string $kind, string $ip = '1.2.3.4', string $sessionId = 'sess-1', string $path = 'live/cam1'): SessionEvent
    {
        return new SessionEvent(
            kind: $kind,
            sessionId: $sessionId,
            path: $path,
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
            return str_contains($job->text, 'Publisher started')
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

    public function test_distinct_publishers_with_empty_id_are_not_collapsed(): void
    {
        Queue::fake();

        $notifier = app(SessionNotifier::class);
        // Empty session id (MediaMTX may omit it); distinct paths must each alert.
        $notifier->notify($this->event('publish', sessionId: '', path: 'live/cam1'), 'started');
        $notifier->notify($this->event('publish', sessionId: '', path: 'live/cam2'), 'started');

        Queue::assertPushed(SendTelegramMessage::class, 2);
    }

    public function test_html_special_characters_in_path_are_escaped(): void
    {
        Queue::fake();

        app(SessionNotifier::class)->notify(
            $this->event('publish', path: 'live/<b>&cam'),
            'started',
        );

        Queue::assertPushed(SendTelegramMessage::class, function (SendTelegramMessage $job) {
            return str_contains($job->text, 'live/&lt;b&gt;&amp;cam')
                && ! str_contains($job->text, 'live/<b>&cam');
        });
    }
}
