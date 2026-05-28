<?php

namespace Tests\Feature\SessionNotifications;

use App\Models\User;
use App\Services\SessionDiff;
use App\Services\SessionEvent;
use App\Services\SessionNotifier;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SessionNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        Cache::flush();
    }

    private function makeUserWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_publish_started_event_creates_one_notification_per_admin_recipient(): void
    {
        $superAdmin = $this->makeUserWithRole('super-admin');
        $moderator = $this->makeUserWithRole('moderator');
        $monitoring = $this->makeUserWithRole('monitoring');

        $event = new SessionEvent('publish', 's-1', 'live/cam1', '1.2.3.4', 'publish', '2026-05-28T10:00:00+00:00');
        $diff = new SessionDiff(started: [$event], ended: []);

        app(SessionNotifier::class)->dispatch($diff);

        $this->assertSame(1, $superAdmin->notifications()->count());
        $this->assertSame(1, $moderator->notifications()->count());
        $this->assertSame(0, $monitoring->notifications()->count());

        $payload = $superAdmin->notifications()->first()->data;
        $this->assertStringContainsString('live/cam1', $payload['title'] ?? json_encode($payload));
    }

    public function test_no_admins_means_no_error_and_no_notifications(): void
    {
        $this->makeUserWithRole('monitoring');

        $event = new SessionEvent('publish', 's-1', 'live/cam1', '1.2.3.4', 'publish', '2026-05-28T10:00:00+00:00');

        app(SessionNotifier::class)->dispatch(new SessionDiff([$event], []));

        $this->assertSame(0, \DB::table('notifications')->count());
    }

    public function test_read_started_is_deduped_by_ip_and_path_within_window(): void
    {
        $admin = $this->makeUserWithRole('super-admin');

        $event = new SessionEvent('read', 's-1', 'live/cam1', '1.2.3.4', 'read', '2026-05-28T10:00:00+00:00');
        $duplicate = new SessionEvent('read', 's-2', 'live/cam1', '1.2.3.4', 'read', '2026-05-28T10:00:30+00:00');
        $different = new SessionEvent('read', 's-3', 'live/cam2', '1.2.3.4', 'read', '2026-05-28T10:00:30+00:00');

        $notifier = app(SessionNotifier::class);
        $notifier->dispatch(new SessionDiff([$event], []));
        $notifier->dispatch(new SessionDiff([$duplicate], []));
        $notifier->dispatch(new SessionDiff([$different], []));

        // event + different = 2; duplicate suppressed.
        $this->assertSame(2, $admin->notifications()->count());
    }

    public function test_publish_started_is_never_deduped(): void
    {
        $admin = $this->makeUserWithRole('super-admin');

        $first = new SessionEvent('publish', 's-1', 'live/cam1', '1.2.3.4', 'publish', '2026-05-28T10:00:00+00:00');
        $second = new SessionEvent('publish', 's-2', 'live/cam1', '1.2.3.4', 'publish', '2026-05-28T10:00:05+00:00');

        app(SessionNotifier::class)->dispatch(new SessionDiff([$first, $second], []));

        $this->assertSame(2, $admin->notifications()->count());
    }

    public function test_read_started_flood_cap_drops_excess_in_same_minute(): void
    {
        $admin = $this->makeUserWithRole('super-admin');

        // 25 unique (ip, path) reads — only 20 should land.
        $events = [];
        for ($i = 0; $i < 25; $i++) {
            $events[] = new SessionEvent(
                kind: 'read',
                sessionId: "s-{$i}",
                path: "live/cam{$i}",
                ip: "10.0.0.{$i}",
                state: 'read',
                occurredAt: '2026-05-28T10:00:00+00:00',
            );
        }

        app(SessionNotifier::class)->dispatch(new SessionDiff($events, []));

        $this->assertSame(20, $admin->notifications()->count());
    }

    public function test_read_ended_uses_separate_dedupe_namespace_from_started(): void
    {
        $admin = $this->makeUserWithRole('super-admin');

        $started = new SessionEvent('read', 's-1', 'live/cam1', '1.2.3.4', 'read', '2026-05-28T10:00:00+00:00');
        $ended = new SessionEvent('read', 's-1', 'live/cam1', '1.2.3.4', 'read', '2026-05-28T10:00:01+00:00');

        app(SessionNotifier::class)->dispatch(new SessionDiff([$started], [$ended]));

        // started + ended = 2; they share (ip, path) but use different dedupe namespaces.
        $this->assertSame(2, $admin->notifications()->count());
    }
}
