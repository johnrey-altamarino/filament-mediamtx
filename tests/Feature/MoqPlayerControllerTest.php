<?php

namespace Tests\Feature;

use App\Http\Middleware\PlayerAccessMiddleware;
use App\Models\PlayerSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoqPlayerControllerTest extends TestCase
{
    use RefreshDatabase;

    private function allowDirect(): void
    {
        // Firing the model event busts the cached 'player_setting:allow_direct_playback'.
        PlayerSetting::create(['key' => 'allow_direct_playback', 'value' => '1']);
    }

    public function test_moq_route_403_when_direct_disabled_and_not_embedded(): void
    {
        $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->withoutVite()
            ->get('/moq?stream=live/a')
            ->assertStatus(403);
    }

    public function test_moq_route_returns_200_when_direct_allowed(): void
    {
        $this->allowDirect();

        $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->withoutVite()
            ->get('/moq?stream=live/a')
            ->assertStatus(200);
    }

    public function test_moq_route_injects_moq_base_url(): void
    {
        $this->allowDirect();
        config(['services.mediamtx.moq_base_url' => 'https://moq.example.test:8892']);

        $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->withoutVite()
            ->get('/moq?stream=live/a')
            ->assertStatus(200)
            ->assertSee('https://moq.example.test:8892', escape: false);
    }

    public function test_moq_route_injects_token_endpoint(): void
    {
        $this->allowDirect();

        $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->withoutVite()
            ->get('/moq?stream=live/a')
            ->assertStatus(200)
            ->assertSee('/api/read', escape: false);
    }
}
