<?php

namespace Tests\Feature;

use App\Services\ActivePublishPaths;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ActivePublishPathsTest extends TestCase
{
    public function test_get_returns_only_ready_publishers_sorted_by_name(): void
    {
        Http::fake(['*/v3/paths/list' => Http::response(['items' => [
            ['name' => 'live/b', 'ready' => true, 'source' => ['type' => 'rtmpConn'], 'tracks' => ['H264'], 'readers' => [['type' => 'webrtcSession']]],
            ['name' => 'live/a', 'ready' => true, 'source' => ['type' => 'webrtcSession'], 'tracks' => ['H264', 'MPEG-4 Audio'], 'readers' => []],
            ['name' => 'live/idle', 'ready' => false, 'source' => null, 'tracks' => [], 'readers' => []],
            ['name' => 'live/noSource', 'ready' => true, 'source' => null, 'tracks' => [], 'readers' => []],
        ]], 200)]);

        $result = app(ActivePublishPaths::class)->get();

        $this->assertCount(2, $result);
        $this->assertSame('live/a', $result[0]['name']);
        $this->assertSame('webrtcSession', $result[0]['source_type']);
        $this->assertSame(['H264', 'MPEG-4 Audio'], $result[0]['tracks']);
        $this->assertSame(0, $result[0]['readers']);
        $this->assertSame('live/b', $result[1]['name']);
        $this->assertSame(1, $result[1]['readers']);
    }

    public function test_get_returns_empty_array_on_api_failure(): void
    {
        Http::fake(['*' => Http::response('err', 500)]);

        $this->assertSame([], app(ActivePublishPaths::class)->get());
    }
}
