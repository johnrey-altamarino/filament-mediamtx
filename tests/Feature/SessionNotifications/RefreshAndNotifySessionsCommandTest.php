<?php

namespace Tests\Feature\SessionNotifications;

use App\Models\User;
use App\Services\MediamtxApi;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class RefreshAndNotifySessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    public function test_command_runs_refresher_and_dispatches_notifications(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $api = Mockery::mock(MediamtxApi::class);
        $api->shouldReceive('listWebRtcSessions')->andReturn([
            'items' => [
                ['id' => 's-new', 'state' => 'publish', 'path' => 'live/cam1',
                    'query' => '', 'remoteAddr' => '1.2.3.4:1234',
                    'created' => '2026-05-28T10:00:00Z', 'bytesReceived' => 0, 'bytesSent' => 0],
            ],
        ]);
        $this->app->instance(MediamtxApi::class, $api);

        $exitCode = Artisan::call('sessions:refresh-and-notify');

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $admin->notifications()->count());
    }

    public function test_command_logs_warning_and_succeeds_when_api_throws(): void
    {
        $api = Mockery::mock(MediamtxApi::class);
        $api->shouldReceive('listWebRtcSessions')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(MediamtxApi::class, $api);

        Log::shouldReceive('warning')->once();

        $exitCode = Artisan::call('sessions:refresh-and-notify');

        $this->assertSame(0, $exitCode);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
