<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MediamtxEventControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.mediamtx.webhook_secret' => 's3cret']);
    }

    private function postEvent(array $payload)
    {
        return $this->withHeaders(['X-Webhook-Secret' => 's3cret'])
            ->postJson('/api/mediamtx/event', $payload);
    }

    public function test_ready_event_returns_204_and_queues_publisher_alert(): void
    {
        Queue::fake();

        $this->postEvent(['event' => 'ready', 'path' => 'live/cam1', 'id' => 'pub-1', 'ip' => '1.2.3.4'])
            ->assertNoContent();

        Queue::assertPushed(SendTelegramMessage::class, function (SendTelegramMessage $job) {
            return str_contains($job->text, 'Publisher started')
                && str_contains($job->text, 'live/cam1')
                && str_contains($job->text, '1.2.3.4');
        });
    }

    public function test_read_event_queues_viewer_alert(): void
    {
        Queue::fake();

        $this->postEvent(['event' => 'read', 'path' => 'live/cam1', 'id' => 'r-1', 'ip' => '9.9.9.9'])
            ->assertNoContent();

        Queue::assertPushed(SendTelegramMessage::class, function (SendTelegramMessage $job) {
            return str_contains($job->text, 'Viewer started')
                && str_contains($job->text, 'live/cam1')
                && str_contains($job->text, '9.9.9.9');
        });
    }

    public function test_started_event_without_ip_resolves_it_from_the_control_api(): void
    {
        Queue::fake();
        Http::fake([
            '*/v3/webrtcsessions/list' => Http::response(['items' => [
                ['id' => 'pub-9', 'remoteAddr' => '203.0.113.7:5555'],
            ]], 200),
        ]);

        $this->postEvent(['event' => 'ready', 'path' => 'live/cam1', 'id' => 'pub-9'])
            ->assertNoContent();

        Queue::assertPushed(SendTelegramMessage::class, function (SendTelegramMessage $job) {
            return str_contains($job->text, '203.0.113.7');
        });
    }

    public function test_started_event_survives_api_failure_with_unknown_ip(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->postEvent(['event' => 'ready', 'path' => 'live/cam1', 'id' => 'pub-x'])
            ->assertNoContent();

        Queue::assertPushed(SendTelegramMessage::class, function (SendTelegramMessage $job) {
            return str_contains($job->text, 'unknown');
        });
    }

    public function test_unknown_event_returns_204_and_queues_nothing(): void
    {
        Queue::fake();

        $this->postEvent(['event' => 'bogus', 'path' => 'live/cam1'])
            ->assertNoContent();

        Queue::assertNothingPushed();
    }

    public function test_missing_path_returns_422(): void
    {
        $this->postEvent(['event' => 'ready'])->assertStatus(422);
    }
}
