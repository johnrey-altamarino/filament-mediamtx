<?php

namespace Tests\Feature;

use App\Http\Middleware\PlayerAccessMiddleware;
use Tests\TestCase;

class PlayerControllerTest extends TestCase
{
    public function test_play_route_returns_200(): void
    {
        $response = $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->get('/play');

        $response->assertStatus(200);
    }

    public function test_play_route_injects_whep_base_url(): void
    {
        config(['services.mediamtx.stream_base_url' => 'https://example-stream.test']);

        $response = $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->get('/play');

        $response->assertStatus(200);
        $response->assertSee('https://example-stream.test', escape: false);
    }

    public function test_play_route_injects_token_endpoint(): void
    {
        $response = $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->get('/play');

        $response->assertStatus(200);
        $response->assertSee('/api/read', escape: false);
    }
}
