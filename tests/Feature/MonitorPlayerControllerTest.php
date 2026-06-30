<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorPlayerControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/monitor/play?stream=live/a')->assertRedirect(route('login'));
    }

    public function test_authenticated_user_gets_player_view_with_self_frame_ancestors(): void
    {
        config(['services.mediamtx.stream_base_url' => 'https://stream.test']);

        $response = $this->actingAs(User::factory()->create())
            ->withoutVite()
            ->get('/monitor/play?stream=live/a');

        $response->assertOk();
        $response->assertSee('https://stream.test', escape: false);
        $response->assertSee('/api/read', escape: false);
        $response->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }
}
