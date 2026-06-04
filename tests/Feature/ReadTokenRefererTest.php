<?php

namespace Tests\Feature;

use App\Services\MediamtxJwtIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadTokenRefererTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://stream.example.com']);
        config(['services.mediamtx.trusted_proxies' => '*']);
        cache()->forget('allowed_referrers');
    }

    public function test_prefix_collision_referer_is_rejected(): void
    {
        // APP_URL is a string prefix of this attacker host, but it is a different origin.
        $response = $this->withHeaders(['Referer' => 'https://stream.example.com.evil.com/'])
            ->postJson('/api/read', ['path' => 'live/stream1']);

        $response->assertStatus(403);
    }

    public function test_exact_origin_referer_is_allowed(): void
    {
        $this->mock(MediamtxJwtIssuer::class, function ($mock) {
            $mock->shouldReceive('issueReadToken')
                ->andReturn(['token' => 'fake.jwt.token', 'expires_at' => now()->addMinute()->timestamp]);
        });

        $response = $this->withHeaders(['Referer' => 'https://stream.example.com/play?stream=live/stream1'])
            ->postJson('/api/read', ['path' => 'live/stream1']);

        $response->assertStatus(200);
        $response->assertJson(['token' => 'fake.jwt.token']);
    }

    public function test_successful_request_caches_referer_for_session_correlation(): void
    {
        $this->mock(MediamtxJwtIssuer::class, function ($mock) {
            $mock->shouldReceive('issueReadToken')
                ->andReturn(['token' => 'fake.jwt.token', 'expires_at' => now()->addMinute()->timestamp]);
        });

        $referer = 'https://stream.example.com/play?stream=live/stream1';
        $this->withHeaders(['Referer' => $referer])
            ->postJson('/api/read', ['path' => 'live/stream1'])
            ->assertStatus(200);

        $this->assertSame($referer, cache()->get('mtx:referer:127.0.0.1:live/stream1'));
    }

    public function test_unrelated_referer_is_rejected(): void
    {
        $response = $this->withHeaders(['Referer' => 'https://evil.com/'])
            ->postJson('/api/read', ['path' => 'live/stream1']);

        $response->assertStatus(403);
    }
}
