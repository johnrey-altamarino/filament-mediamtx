<?php

namespace Tests\Feature\SessionNotifications;

use App\Models\MediamtxSession;
use App\Services\MediamtxApi;
use App\Services\MediamtxSessionRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_upserts_live_rows_and_prunes_stale(): void
    {
        // Pre-existing stale row that is NOT in the API response.
        MediamtxSession::create([
            'type' => 'webrtc', 'session_id' => 'old', 'state' => 'read',
            'path' => 'live/old', 'ip' => '5.5.5.5',
            'last_seen_at' => now()->subSeconds(60),
        ]);

        $api = Mockery::mock(MediamtxApi::class);
        $api->shouldReceive('listWebRtcSessions')->andReturn([
            'items' => [
                ['id' => 'live-1', 'state' => 'read', 'path' => 'live/cam1',
                    'query' => '', 'remoteAddr' => '1.2.3.4:5555', 'created' => '2026-06-04T10:00:00Z',
                    'userAgent' => 'Mozilla/5.0 (OBS-Studio/32.1.1; Windows x86_64; en-US)'],
            ],
        ]);
        $this->app->instance(MediamtxApi::class, $api);

        app(MediamtxSessionRefresher::class)->syncSnapshot();

        $this->assertDatabaseHas('mediamtx_sessions', [
            'session_id' => 'live-1',
            'ip' => '1.2.3.4',
            'user_agent' => 'Mozilla/5.0 (OBS-Studio/32.1.1; Windows x86_64; en-US)',
        ]);
        $this->assertDatabaseMissing('mediamtx_sessions', ['session_id' => 'old']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
