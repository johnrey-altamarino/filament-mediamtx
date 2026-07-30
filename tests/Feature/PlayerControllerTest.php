<?php

namespace Tests\Feature;

use App\Http\Middleware\PlayerAccessMiddleware;
use App\Models\PlayerSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerControllerTest extends TestCase
{
    use RefreshDatabase;

    private function allowDirect(): void
    {
        // Firing the model event busts the cached 'player_setting:allow_direct_playback'.
        PlayerSetting::create(['key' => 'allow_direct_playback', 'value' => '1']);
    }

    public function test_play_route_returns_200(): void
    {
        $this->allowDirect();

        $response = $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->withoutVite()
            ->get('/play');

        $response->assertStatus(200);
    }

    public function test_play_route_injects_whep_base_url(): void
    {
        $this->allowDirect();
        config(['services.mediamtx.stream_base_url' => 'https://example-stream.test']);

        $response = $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->withoutVite()
            ->get('/play');

        $response->assertStatus(200);
        $response->assertSee('https://example-stream.test', escape: false);
    }

    public function test_play_route_injects_token_endpoint(): void
    {
        $this->allowDirect();

        $response = $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->withoutVite()
            ->get('/play');

        $response->assertStatus(200);
        $response->assertSee('/api/read', escape: false);
    }

    public function test_play_route_403_renders_branded_error_page(): void
    {
        $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->withoutVite()
            ->get('/play')
            ->assertStatus(403)
            ->assertSee('Stream unavailable');
    }
}
