<?php

namespace Tests\Feature;

use Tests\TestCase;

class MediamtxWebhookSecurityTest extends TestCase
{
    public function test_request_without_secret_is_forbidden(): void
    {
        config(['services.mediamtx.webhook_secret' => 's3cret']);

        $this->postJson('/api/mediamtx/event', ['event' => 'ready', 'path' => 'live/cam1'])
            ->assertStatus(403);
    }

    public function test_request_with_wrong_secret_is_forbidden(): void
    {
        config(['services.mediamtx.webhook_secret' => 's3cret']);

        $this->withHeaders(['X-Webhook-Secret' => 'nope'])
            ->postJson('/api/mediamtx/event', ['event' => 'ready', 'path' => 'live/cam1'])
            ->assertStatus(403);
    }

    public function test_request_is_forbidden_when_secret_unconfigured(): void
    {
        config(['services.mediamtx.webhook_secret' => null]);

        $this->withHeaders(['X-Webhook-Secret' => 'anything'])
            ->postJson('/api/mediamtx/event', ['event' => 'ready', 'path' => 'live/cam1'])
            ->assertStatus(403);
    }
}
