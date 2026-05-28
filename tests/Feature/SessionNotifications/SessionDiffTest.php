<?php

namespace Tests\Feature\SessionNotifications;

use App\Services\SessionDiff;
use App\Services\SessionEvent;
use Tests\TestCase;

class SessionDiffTest extends TestCase
{
    public function test_event_carries_required_fields(): void
    {
        $event = new SessionEvent(
            kind: 'publish',
            sessionId: 'abc-123',
            path: 'live/cam1',
            ip: '1.2.3.4',
            state: 'publish',
            occurredAt: '2026-05-28T10:00:00+00:00',
        );

        $this->assertSame('publish', $event->kind);
        $this->assertSame('abc-123', $event->sessionId);
        $this->assertSame('live/cam1', $event->path);
        $this->assertSame('1.2.3.4', $event->ip);
        $this->assertSame('publish', $event->state);
        $this->assertSame('2026-05-28T10:00:00+00:00', $event->occurredAt);
    }

    public function test_diff_groups_started_and_ended_events(): void
    {
        $started = new SessionEvent('publish', 's1', 'live/cam1', '1.2.3.4', 'publish', '2026-05-28T10:00:00+00:00');
        $ended = new SessionEvent('read', 's2', 'live/cam1', '5.6.7.8', 'read', '2026-05-28T10:01:00+00:00');

        $diff = new SessionDiff(started: [$started], ended: [$ended]);

        $this->assertCount(1, $diff->started);
        $this->assertCount(1, $diff->ended);
        $this->assertSame($started, $diff->started[0]);
        $this->assertSame($ended, $diff->ended[0]);
    }
}
