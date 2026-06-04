<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramMessage;
use App\Services\TelegramNotifier;
use Mockery;
use Tests\TestCase;

class SendTelegramMessageJobTest extends TestCase
{
    public function test_job_calls_notifier_with_text(): void
    {
        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldReceive('send')->once()->with('ping');

        (new SendTelegramMessage('ping'))->handle($notifier);

        // The Mockery expectation above is the assertion (verified on tearDown).
        $this->addToAssertionCount(1);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
