<?php

namespace Tests\Feature\SessionNotifications;

use App\Models\User;
use App\Services\SessionDiff;
use App\Services\SessionEvent;
use App\Services\SessionNotifier;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
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
}
