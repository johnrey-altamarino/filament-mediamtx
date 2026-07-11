<?php

namespace Tests\Feature;

use App\Services\MediamtxApi;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MediamtxApiTest extends TestCase
{
    public function test_ip_from_remote_addr_parses_ipv4_ipv6_and_plain(): void
    {
        $this->assertSame('1.2.3.4', MediamtxApi::ipFromRemoteAddr('1.2.3.4:5555'));
        $this->assertSame('2001:db8::1', MediamtxApi::ipFromRemoteAddr('[2001:db8::1]:6666'));
        $this->assertSame('1.2.3.4', MediamtxApi::ipFromRemoteAddr('1.2.3.4'));
        $this->assertSame('', MediamtxApi::ipFromRemoteAddr(''));
    }

    public function test_ip_from_candidate_parses_ip_from_ice_candidate(): void
    {
        $this->assertSame('49.146.39.58', MediamtxApi::ipFromCandidate('prflx/udp/49.146.39.58/10119'));
        $this->assertSame('192.168.1.74', MediamtxApi::ipFromCandidate('host/udp/192.168.1.74/58898'));
        $this->assertSame('2001:db8::1', MediamtxApi::ipFromCandidate('host/udp/2001:db8::1/1234'));
        $this->assertSame('', MediamtxApi::ipFromCandidate(''));
        $this->assertSame('', MediamtxApi::ipFromCandidate('garbage'));
        $this->assertSame('', MediamtxApi::ipFromCandidate('prflx/udp/not-an-ip/10119'));
    }

    public function test_client_ip_prefers_remote_addr_over_remote_candidate(): void
    {
        // remoteAddr (signaling peer) is preferred when present.
        $this->assertSame('2001:db8::1', MediamtxApi::clientIp([
            'remoteAddr' => '[2001:db8::1]:443',
            'remoteCandidate' => 'prflx/udp/49.146.39.58/10119',
        ]));

        $this->assertSame('1.2.3.4', MediamtxApi::clientIp([
            'remoteAddr' => '1.2.3.4:5555',
        ]));

        // No remoteAddr yet → fall back to the ICE remote candidate.
        $this->assertSame('49.146.39.58', MediamtxApi::clientIp([
            'remoteCandidate' => 'prflx/udp/49.146.39.58/10119',
        ]));

        $this->assertSame('', MediamtxApi::clientIp([]));
    }

    public function test_find_session_uses_remote_addr_ip(): void
    {
        Http::fake(['*/v3/webrtcsessions/list' => Http::response(['items' => [
            ['id' => 'a', 'remoteAddr' => '[2001:db8::1]:443',
                'remoteCandidate' => 'prflx/udp/49.146.39.58/10119', 'userAgent' => 'Chrome'],
        ]], 200)]);

        $this->assertSame(
            ['ip' => '2001:db8::1', 'userAgent' => 'Chrome'],
            app(MediamtxApi::class)->findSession('a')
        );
    }

    public function test_find_session_ip_matches_by_id(): void
    {
        Http::fake(['*/v3/webrtcsessions/list' => Http::response(['items' => [
            ['id' => 'a', 'remoteAddr' => '1.1.1.1:1'],
            ['id' => 'b', 'remoteAddr' => '[2001:db8::5]:2'],
        ]], 200)]);

        $api = app(MediamtxApi::class);
        $this->assertSame('1.1.1.1', $api->findSessionIp('a'));
        $this->assertSame('2001:db8::5', $api->findSessionIp('b'));
        $this->assertSame('', $api->findSessionIp('missing'));
        $this->assertSame('', $api->findSessionIp(''));
    }

    public function test_find_session_ip_returns_empty_on_api_failure(): void
    {
        Http::fake(['*' => Http::response('err', 500)]);

        $this->assertSame('', app(MediamtxApi::class)->findSessionIp('a'));
    }

    public function test_find_session_returns_ip_and_user_agent_by_id(): void
    {
        Http::fake(['*/v3/webrtcsessions/list' => Http::response(['items' => [
            ['id' => 'a', 'remoteAddr' => '1.1.1.1:1', 'userAgent' => 'OBS-Studio/32.1.1'],
            ['id' => 'b', 'remoteAddr' => '2.2.2.2:2'],
        ]], 200)]);

        $api = app(MediamtxApi::class);

        $this->assertSame(['ip' => '1.1.1.1', 'userAgent' => 'OBS-Studio/32.1.1'], $api->findSession('a'));
        // Session without a userAgent field → empty string, not null.
        $this->assertSame(['ip' => '2.2.2.2', 'userAgent' => ''], $api->findSession('b'));
        $this->assertSame(['ip' => '', 'userAgent' => ''], $api->findSession('missing'));
        $this->assertSame(['ip' => '', 'userAgent' => ''], $api->findSession(''));
    }

    public function test_find_session_returns_empty_on_api_failure(): void
    {
        Http::fake(['*' => Http::response('err', 500)]);

        $this->assertSame(['ip' => '', 'userAgent' => ''], app(MediamtxApi::class)->findSession('a'));
    }

    public function test_list_paths_returns_decoded_items(): void
    {
        Http::fake(['*/v3/paths/list' => Http::response(['items' => [
            ['name' => 'live/a', 'ready' => true, 'source' => ['type' => 'webrtcSession']],
        ]], 200)]);

        $res = app(MediamtxApi::class)->listPaths();

        $this->assertSame('live/a', $res['items'][0]['name']);
    }

    public function test_list_moq_sessions_returns_decoded_items(): void
    {
        Http::fake(['*/v3/moqsessions/list' => Http::response(['items' => [
            ['id' => 'm-1', 'path' => 'live/cam1', 'state' => 'read', 'remoteAddr' => '5.6.7.8:1111'],
        ]], 200)]);

        $items = data_get(app(MediamtxApi::class)->listMoqSessions(), 'items');

        $this->assertSame('m-1', $items[0]['id']);
    }

    public function test_kick_routes_to_protocol_endpoint(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $api = app(MediamtxApi::class);

        $api->kick('webrtc', 'w1');
        $api->kick('moq', 'm1');
        $api->kick('rtmp', 'r1');
        $api->kick('rtsp', 's1');
        $api->kick('srt', 't1');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v3/webrtcsessions/kick/w1'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v3/moqsessions/kick/m1'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v3/rtmpconns/kick/r1'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v3/rtspsessions/kick/s1'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v3/srtconns/kick/t1'));
    }

    public function test_kick_rejects_unkickable_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(MediamtxApi::class)->kick('hls', 'x1');
    }

    public function test_find_session_enriches_moq_by_type(): void
    {
        Http::fake(['*/v3/moqsessions/list' => Http::response(['items' => [
            ['id' => 'm-1', 'remoteAddr' => '5.6.7.8:1111', 'userAgent' => ''],
        ]], 200)]);

        $result = app(MediamtxApi::class)->findSession('m-1', 'moqSession');

        $this->assertSame('5.6.7.8', $result['ip']);
    }
}
