<?php

namespace Tests\Unit;

use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramNotifierTest extends TestCase
{
    public function test_it_posts_message_to_telegram_when_enabled(): void
    {
        config([
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => 'BOT123',
            'services.telegram.chat_id' => '99',
            'services.telegram.timeout' => 5,
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        app(TelegramNotifier::class)->send('hello world');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/botBOT123/sendMessage')
                && $request['chat_id'] === '99'
                && $request['text'] === 'hello world';
        });
    }

    public function test_it_is_a_noop_when_disabled(): void
    {
        config(['services.telegram.enabled' => false]);
        Http::fake();

        app(TelegramNotifier::class)->send('hello');

        Http::assertNothingSent();
    }

    public function test_it_is_a_noop_when_token_or_chat_missing(): void
    {
        config([
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => null,
            'services.telegram.chat_id' => '99',
        ]);
        Http::fake();

        app(TelegramNotifier::class)->send('hello');

        Http::assertNothingSent();
    }
}
