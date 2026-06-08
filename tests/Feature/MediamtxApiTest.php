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
}
