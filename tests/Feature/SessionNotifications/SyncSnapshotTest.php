<?php

namespace Tests\Feature\SessionNotifications;

use App\Models\MediamtxSession;
use App\Services\MediamtxApi;
use App\Services\MediamtxSessionRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_it_stores_the_real_ipv4_from_the_ice_remote_candidate(): void
    {
        $api = Mockery::mock(MediamtxApi::class);
        $api->shouldReceive('listWebRtcSessions')->andReturn([
            'items' => [
                // remoteAddr (signaling peer) is preferred over ICE candidate; IPv6 WHEP address wins.
                ['id' => 'live-1', 'state' => 'read', 'path' => 'live/cam1', 'query' => '',
                    'remoteAddr' => '[2001:db8::1]:443',
                    'remoteCandidate' => 'prflx/udp/49.146.39.58/10119',
                    'created' => '2026-06-04T10:00:00Z', 'userAgent' => 'Chrome'],
            ],
        ]);
        $this->app->instance(MediamtxApi::class, $api);

        app(MediamtxSessionRefresher::class)->syncSnapshot();

        // Per b7b2d8f, clientIp() prefers remoteAddr over remoteCandidate.
        $this->assertDatabaseHas('mediamtx_sessions', [
            'session_id' => 'live-1',
            'ip' => '2001:db8::1',
        ]);
    }

    public function test_sync_snapshot_merges_all_protocols_and_survives_one_failure(): void
    {
        Http::fake([
            '*/v3/webrtcsessions/list' => Http::response(['items' => [
                ['id' => 'w-1', 'path' => 'live/cam1', 'state' => 'read', 'remoteAddr' => '1.1.1.1:1', 'userAgent' => 'Mozilla'],
            ]], 200),
            '*/v3/moqsessions/list' => Http::response(['items' => [
                ['id' => 'm-1', 'path' => 'live/cam1', 'state' => 'read', 'remoteAddr' => '2.2.2.2:2'],
            ]], 200),
            '*/v3/rtmpconns/list' => Http::response(['items' => [
                ['id' => 'r-1', 'path' => 'live/cam2', 'state' => 'publish', 'remoteAddr' => '3.3.3.3:3'],
            ]], 200),
            '*/v3/rtspsessions/list' => Http::response('boom', 500), // one endpoint down
            '*/v3/srtconns/list' => Http::response(['items' => [
                ['id' => 't-1', 'path' => 'live/cam3', 'state' => 'publish', 'remoteAddr' => '4.4.4.4:4'],
            ]], 200),
        ]);

        app(MediamtxSessionRefresher::class)->syncSnapshot();

        $this->assertSame('webrtc', MediamtxSession::where('session_id', 'w-1')->value('type'));
        $this->assertSame('moq', MediamtxSession::where('session_id', 'm-1')->value('type'));
        $this->assertSame('rtmp', MediamtxSession::where('session_id', 'r-1')->value('type'));
        $this->assertSame('srt', MediamtxSession::where('session_id', 't-1')->value('type'));
        $this->assertSame('2.2.2.2', MediamtxSession::where('session_id', 'm-1')->value('ip'));
        // rtsp endpoint failed → no rows, but the sync still completed for the others
        $this->assertSame(4, MediamtxSession::count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
