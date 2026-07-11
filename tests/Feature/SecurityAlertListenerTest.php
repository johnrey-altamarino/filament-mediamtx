<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SecurityAlertListenerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.enabled' => true, 'services.telegram.security_alerts_enabled' => true]);
        Cache::flush();
    }

    public function test_allowlisted_security_event_dispatches_alert(): void
    {
        Queue::fake();

        activity('security')->withProperties(['ip' => '9.9.9.9', 'path' => 'live/cam1'])->log('session.kicked');

        Queue::assertPushed(SendTelegramMessage::class, function (SendTelegramMessage $job) {
            return str_contains($job->text, 'Session kicked')
                && str_contains($job->text, '9.9.9.9');
        });
    }

    public function test_non_allowlisted_security_event_is_ignored(): void
    {
        Queue::fake();

        activity('security')->log('referrer.added');

        Queue::assertNotPushed(SendTelegramMessage::class);
    }

    public function test_non_security_channel_is_ignored(): void
    {
        Queue::fake();

        activity()->log('session.kicked'); // default log channel, not "security"

        Queue::assertNotPushed(SendTelegramMessage::class);
    }

    public function test_ip_blocked_from_top_ips_dispatches_alert(): void
    {
        Queue::fake();

        activity('security')->withProperties(['ip' => '8.8.8.8'])->log('ip.blocked.from_top_ips');

        Queue::assertPushed(SendTelegramMessage::class, function (SendTelegramMessage $job) {
            return str_contains($job->text, 'IP blocked')
                && str_contains($job->text, '8.8.8.8');
        });
    }
}
