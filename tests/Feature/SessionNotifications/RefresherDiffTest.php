<?php

namespace Tests\Feature\SessionNotifications;

use App\Models\MediamtxSession;
use App\Services\MediamtxApi;
use App\Services\MediamtxSessionRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RefresherDiffTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_started_for_new_sessions_and_ended_for_missing_ones(): void
    {
        // Existing DB rows: s-old-read (read), s-old-pub (publish) — s-old-read will be marked ended.
        MediamtxSession::create([
            'type' => 'webrtc', 'session_id' => 's-old-read',
            'state' => 'read', 'path' => 'live/cam1', 'query' => '',
            'remote_addr' => '1.2.3.4:1234', 'ip' => '1.2.3.4',
            'created' => '2026-05-28T09:00:00Z', 'bytes_received' => 0, 'bytes_sent' => 0,
            'last_seen_at' => now(),
        ]);
        MediamtxSession::create([
            'type' => 'webrtc', 'session_id' => 's-old-pub',
            'state' => 'publish', 'path' => 'live/cam1', 'query' => '',
            'remote_addr' => '5.6.7.8:1234', 'ip' => '5.6.7.8',
            'created' => '2026-05-28T09:00:00Z', 'bytes_received' => 0, 'bytes_sent' => 0,
            'last_seen_at' => now(),
        ]);

        // API returns s-old-pub (still active) and s-new-read (new). s-old-read is gone.
        $api = Mockery::mock(MediamtxApi::class);
        $api->shouldReceive('listWebRtcSessions')->andReturn([
            'items' => [
                ['id' => 's-old-pub', 'state' => 'publish', 'path' => 'live/cam1',
                    'query' => '', 'remoteAddr' => '5.6.7.8:1234',
                    'created' => '2026-05-28T09:00:00Z', 'bytesReceived' => 100, 'bytesSent' => 0],
                ['id' => 's-new-read', 'state' => 'read', 'path' => 'live/cam1',
                    'query' => '', 'remoteAddr' => '9.9.9.9:5555',
                    'created' => '2026-05-28T10:00:00Z', 'bytesReceived' => 0, 'bytesSent' => 50],
            ],
        ]);

        $diff = (new MediamtxSessionRefresher($api))->refresh();

        $startedIds = array_map(fn ($e) => $e->sessionId, $diff->started);
        $endedIds = array_map(fn ($e) => $e->sessionId, $diff->ended);

        $this->assertSame(['s-new-read'], $startedIds);
        $this->assertSame(['s-old-read'], $endedIds);
        $this->assertSame('read', $diff->started[0]->kind);
        $this->assertSame('read', $diff->ended[0]->kind);
        $this->assertSame('live/cam1', $diff->started[0]->path);
        $this->assertSame('9.9.9.9', $diff->started[0]->ip);
    }

    public function test_unknown_state_is_excluded_from_diff(): void
    {
        $api = Mockery::mock(MediamtxApi::class);
        $api->shouldReceive('listWebRtcSessions')->andReturn([
            'items' => [
                ['id' => 's-weird', 'state' => 'something-else', 'path' => 'live/cam1',
                    'query' => '', 'remoteAddr' => '1.1.1.1:1',
                    'created' => '2026-05-28T10:00:00Z', 'bytesReceived' => 0, 'bytesSent' => 0],
            ],
        ]);

        $diff = (new MediamtxSessionRefresher($api))->refresh();

        $this->assertSame([], $diff->started);
        $this->assertSame([], $diff->ended);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
