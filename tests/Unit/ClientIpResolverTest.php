<?php

namespace Tests\Unit;

use App\Services\ClientIpResolver;
use Illuminate\Http\Request;
use Tests\TestCase;

class ClientIpResolverTest extends TestCase
{
    private function request(array $headers = [], string $remoteAddr = '203.0.113.10'): Request
    {
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => $remoteAddr]);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    public function test_untrusted_peer_cannot_spoof_cf_connecting_ip(): void
    {
        config(['services.mediamtx.trusted_proxies' => '']);

        $ip = (new ClientIpResolver)->resolve(
            $this->request(['CF-Connecting-IP' => '1.2.3.4'], remoteAddr: '203.0.113.10')
        );

        $this->assertSame('203.0.113.10', $ip);
    }

    public function test_untrusted_peer_cannot_spoof_x_forwarded_for(): void
    {
        config(['services.mediamtx.trusted_proxies' => '']);

        $ip = (new ClientIpResolver)->resolve(
            $this->request(['X-Forwarded-For' => '1.2.3.4'], remoteAddr: '203.0.113.10')
        );

        $this->assertSame('203.0.113.10', $ip);
    }

    public function test_trusted_proxy_peer_may_supply_cf_connecting_ip(): void
    {
        config(['services.mediamtx.trusted_proxies' => '203.0.113.0/24']);

        $ip = (new ClientIpResolver)->resolve(
            $this->request(['CF-Connecting-IP' => '1.2.3.4'], remoteAddr: '203.0.113.10')
        );

        $this->assertSame('1.2.3.4', $ip);
    }

    public function test_wildcard_trusts_any_peer(): void
    {
        config(['services.mediamtx.trusted_proxies' => '*']);

        $ip = (new ClientIpResolver)->resolve(
            $this->request(['CF-Connecting-IP' => '1.2.3.4'], remoteAddr: '198.51.100.7')
        );

        $this->assertSame('1.2.3.4', $ip);
    }

    public function test_falls_back_to_peer_when_no_forwarding_headers(): void
    {
        config(['services.mediamtx.trusted_proxies' => '*']);

        $ip = (new ClientIpResolver)->resolve($this->request([], remoteAddr: '198.51.100.7'));

        $this->assertSame('198.51.100.7', $ip);
    }
}
