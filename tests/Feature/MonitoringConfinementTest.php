<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringConfinementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        // Rendering /monitor calls the MediaMTX paths API; stub it so the
        // "can load monitor" test never touches the network.
        Http::fake(['*/v3/paths/list' => Http::response(['items' => []], 200)]);
    }

    private function monitoringUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('monitoring');

        return $user;
    }

    public function test_monitoring_user_is_redirected_from_dashboard(): void
    {
        $this->actingAs($this->monitoringUser())
            ->get('/')
            ->assertRedirect(route('monitor'));
    }

    public function test_monitoring_user_is_redirected_from_a_resource(): void
    {
        $this->actingAs($this->monitoringUser())
            ->get('/publish-tokens')
            ->assertRedirect(route('monitor'));
    }

    public function test_monitoring_user_can_load_monitor(): void
    {
        $this->actingAs($this->monitoringUser())
            ->withoutVite()
            ->get('/monitor')
            ->assertOk();
    }

    public function test_moderator_can_load_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('moderator');

        $this->actingAs($user)
            ->withoutVite()
            ->get('/')
            ->assertOk();
    }

    public function test_super_admin_can_load_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $this->actingAs($user)
            ->withoutVite()
            ->get('/')
            ->assertOk();
    }

    public function test_monitoring_user_can_reach_logout(): void
    {
        $this->actingAs($this->monitoringUser())
            ->post('/logout')
            ->assertRedirect('/login');
    }
}
