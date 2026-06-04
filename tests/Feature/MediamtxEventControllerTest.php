<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramMessage;
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
            return str_contains($job->text, 'New publisher on live/cam1');
        });
    }

    public function test_read_event_queues_viewer_alert(): void
    {
        Queue::fake();

        $this->postEvent(['event' => 'read', 'path' => 'live/cam1', 'id' => 'r-1', 'ip' => '9.9.9.9'])
            ->assertNoContent();

        Queue::assertPushed(SendTelegramMessage::class, function (SendTelegramMessage $job) {
            return str_contains($job->text, 'New viewer on live/cam1');
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
