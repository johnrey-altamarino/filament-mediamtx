# Telegram Alerts via MediaMTX Hooks + Query-Lean Sessions — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace 24/7 background MediaMTX polling with event-driven Telegram alerts (MediaMTX hooks → localhost webhook → queued Telegram send), and make the Sessions table sync only while it is being viewed.

**Architecture:** Two independent, non-background paths. (1) Alerting: MediaMTX hook commands `curl` a secret-protected Laravel webhook on session start/stop; the controller builds a `SessionEvent` and hands it to the repurposed `SessionNotifier`, which de-dupes and dispatches a queued `SendTelegramMessage` job. (2) Viewing: `MediamtxSessionRefresher::syncSnapshot()` upserts/prunes the `mediamtx_sessions` table, called only from the Sessions page poll and the dashboard widget poll (i.e. only while an admin is looking).

**Tech Stack:** Laravel 12, PHP 8.2, Filament v3, PHPUnit 11, Mockery, `Http`/`Queue` fakes. Tests run with `QUEUE_CONNECTION=sync` and `CACHE_STORE=array` (see `phpunit.xml`).

**Spec:** `docs/superpowers/specs/2026-06-04-telegram-alerts-query-lean-design.md`

---

## File Structure

**Create:**
- `app/Services/TelegramNotifier.php` — formats + sends one Telegram message via `Http`.
- `app/Jobs/SendTelegramMessage.php` — queued wrapper around `TelegramNotifier`.
- `app/Http/Middleware/VerifyMediamtxWebhook.php` — constant-time shared-secret check.
- `app/Http/Controllers/Api/MediamtxEventController.php` — maps hook payload → `SessionEvent` → `SessionNotifier`.
- `database/migrations/2026_06_04_000000_drop_bytes_from_mediamtx_sessions.php` — drops `bytes_*`.
- `docs/mediamtx-hooks.md` — the `mediamtx.yml` hook snippet for the operator.
- Tests under `tests/Feature` / `tests/Unit` as listed per task.

**Modify:**
- `config/services.php` — `telegram` block + `mediamtx.webhook_secret`.
- `bootstrap/app.php` — register `mediamtx.webhook` middleware alias.
- `routes/api.php` — add `POST /api/mediamtx/event`.
- `app/Services/SessionNotifier.php` — deliver via Telegram instead of `sendToDatabase`.
- `app/Services/MediamtxSessionRefresher.php` — reduce to `syncSnapshot()`, drop bytes.
- `app/Models/MediamtxSession.php` — drop `bytes_*` from `$fillable`/`$casts`.
- `app/Filament/Pages/Sessions.php` — `refreshSessions()` calls sync; drop bytes columns.
- `resources/views/filament/pages/sessions.blade.php` — poll `10s`.
- `app/Filament/Widgets/SessionsOverview.php` — call `syncSnapshot()`.
- `app/Providers/Filament/AdminPanelProvider.php` — remove database-notification bell.
- `routes/console.php` — remove the scheduled refresh command.

**Delete:**
- `app/Console/Commands/RefreshAndNotifySessions.php`
- `app/Services/SessionDiff.php`
- `tests/Feature/SessionNotifications/RefreshAndNotifySessionsCommandTest.php`
- `tests/Feature/SessionNotifications/RefresherDiffTest.php`
- `tests/Feature/SessionNotifications/SessionDiffTest.php`

**Keep:** `app/Services/SessionEvent.php` (reused by the webhook + notifier).

---

## Task 1: Telegram config

**Files:**
- Modify: `config/services.php`

- [ ] **Step 1: Add the config blocks**

In `config/services.php`, add `webhook_secret` to the existing `mediamtx` array and add a new `telegram` array. Insert `webhook_secret` inside the `'mediamtx' => [...]` block:

```php
        'webhook_secret' => env('MEDIAMTX_WEBHOOK_SECRET'),
```

Add a new top-level block after the `mediamtx_jwt` block:

```php
    'telegram' => [
        'enabled' => (bool) env('TELEGRAM_ALERTS_ENABLED', false),
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'timeout' => (int) env('TELEGRAM_TIMEOUT', 5),
    ],
```

- [ ] **Step 2: Document the new env vars**

Append to `.env.example` (create the lines if absent):

```
TELEGRAM_ALERTS_ENABLED=false
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
MEDIAMTX_WEBHOOK_SECRET=
```

- [ ] **Step 3: Commit**

```bash
git add config/services.php .env.example
git commit -m "feat: add telegram + mediamtx webhook config"
```

---

## Task 2: TelegramNotifier service

**Files:**
- Create: `app/Services/TelegramNotifier.php`
- Test: `tests/Unit/TelegramNotifierTest.php`

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter TelegramNotifierTest`
Expected: FAIL — `Class "App\Services\TelegramNotifier" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TelegramNotifier
{
    public function send(string $text): void
    {
        if (! config('services.telegram.enabled')) {
            return;
        }

        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (! $token || ! $chatId) {
            return;
        }

        Http::timeout((int) config('services.telegram.timeout', 5))
            ->asForm()
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => (string) $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter TelegramNotifierTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/TelegramNotifier.php tests/Unit/TelegramNotifierTest.php
git commit -m "feat: add TelegramNotifier service"
```

---

## Task 3: SendTelegramMessage queued job

**Files:**
- Create: `app/Jobs/SendTelegramMessage.php`
- Test: `tests/Feature/SendTelegramMessageJobTest.php`

- [ ] **Step 1: Write the failing test**

```php
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
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter SendTelegramMessageJobTest`
Expected: FAIL — `Class "App\Jobs\SendTelegramMessage" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Jobs;

use App\Services\TelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public readonly string $text) {}

    public function handle(TelegramNotifier $notifier): void
    {
        $notifier->send($this->text);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter SendTelegramMessageJobTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/SendTelegramMessage.php tests/Feature/SendTelegramMessageJobTest.php
git commit -m "feat: add SendTelegramMessage queued job"
```

---

## Task 4: Repurpose SessionNotifier to deliver via Telegram

**Files:**
- Modify: `app/Services/SessionNotifier.php`
- Test: `tests/Feature/SessionNotifications/SessionNotifierTest.php` (rewrite)

Note: `SessionNotifier` currently takes a `SessionDiff` and calls `->sendToDatabase()`. We replace `dispatch(SessionDiff)` / `recipients()` with a single `notify(SessionEvent $event, string $phase)` entry point that de-dupes (keep existing logic verbatim) and dispatches `SendTelegramMessage`. Keep `passesDedupe()` and `passesReadFloodCap()` unchanged.

- [ ] **Step 1: Rewrite the test**

Replace the contents of `tests/Feature/SessionNotifications/SessionNotifierTest.php`:

```php
<?php

namespace Tests\Feature\SessionNotifications;

use App\Jobs\SendTelegramMessage;
use App\Services\SessionEvent;
use App\Services\SessionNotifier;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SessionNotifierTest extends TestCase
{
    private function event(string $kind, string $ip = '1.2.3.4'): SessionEvent
    {
        return new SessionEvent(
            kind: $kind,
            sessionId: 'sess-1',
            path: 'live/cam1',
            ip: $ip,
            state: $kind,
            occurredAt: '2026-06-04T10:00:00Z',
        );
    }

    public function test_publish_started_dispatches_one_telegram_job(): void
    {
        Queue::fake();

        app(SessionNotifier::class)->notify($this->event('publish'), 'started');

        Queue::assertPushed(SendTelegramMessage::class, function (SendTelegramMessage $job) {
            return str_contains($job->text, 'New publisher on')
                && str_contains($job->text, 'live/cam1');
        });
    }

    public function test_duplicate_event_within_ttl_is_suppressed(): void
    {
        Queue::fake();

        $notifier = app(SessionNotifier::class);
        $notifier->notify($this->event('publish'), 'started');
        $notifier->notify($this->event('publish'), 'started');

        Queue::assertPushed(SendTelegramMessage::class, 1);
    }

    public function test_read_flood_cap_suppresses_excess(): void
    {
        Queue::fake();

        $notifier = app(SessionNotifier::class);
        for ($i = 0; $i < 25; $i++) {
            $notifier->notify($this->event('read', "10.0.0.$i"), 'started');
        }

        // READ_FLOOD_CAP_PER_MINUTE = 20
        Queue::assertPushed(SendTelegramMessage::class, 20);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter SessionNotifierTest`
Expected: FAIL — `notify()` does not exist on `SessionNotifier`.

- [ ] **Step 3: Edit SessionNotifier**

Replace the top of the class (imports + `dispatch()`/`maybeSend()`/`recipients()`), keeping `passesDedupe()` and `passesReadFloodCap()` exactly as they are. New imports block:

```php
use App\Jobs\SendTelegramMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
```

Remove the now-unused imports (`App\Filament\Pages\Sessions`, `App\Models\User`, `Filament\Notifications\*`, `Illuminate\Support\Collection`).

Replace `dispatch()` and `maybeSend()` and `recipients()` with:

```php
    public function notify(SessionEvent $event, string $phase): void
    {
        $titleVerb = match ([$event->kind, $phase]) {
            ['publish', 'started'] => 'New publisher on',
            ['publish', 'ended'] => 'Publisher ended on',
            ['read', 'started'] => 'New viewer on',
            ['read', 'ended'] => 'Viewer ended on',
            default => null,
        };

        if ($titleVerb === null) {
            return;
        }

        if (! $this->passesDedupe($event, $phase)) {
            return;
        }

        if ($event->kind === 'read' && $phase === 'started' && ! $this->passesReadFloodCap()) {
            return;
        }

        $when = Carbon::parse($event->occurredAt)->diffForHumans();
        $ip = $event->ip !== '' ? $event->ip : 'unknown';

        $text = "<b>{$titleVerb} {$event->path}</b>\nIP {$ip} · {$when}";

        SendTelegramMessage::dispatch($text);
    }
```

Leave `READ_DEDUPE_TTL_SECONDS`, `READ_FLOOD_CAP_PER_MINUTE`, `passesDedupe()`, and `passesReadFloodCap()` untouched.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter SessionNotifierTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/SessionNotifier.php tests/Feature/SessionNotifications/SessionNotifierTest.php
git commit -m "refactor: SessionNotifier delivers session events via Telegram"
```

---

## Task 5: Webhook secret middleware

**Files:**
- Create: `app/Http/Middleware/VerifyMediamtxWebhook.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/MediamtxWebhookSecurityTest.php`

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter MediamtxWebhookSecurityTest`
Expected: FAIL — route `/api/mediamtx/event` not defined (404), so assertion 403 fails. (We wire the route in Task 6; for now the middleware + a temporary route in Step 3 make the security tests pass.)

- [ ] **Step 3: Write the middleware and register it**

Create `app/Http/Middleware/VerifyMediamtxWebhook.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyMediamtxWebhook
{
    public function handle(Request $request, Closure $next)
    {
        $configured = (string) config('services.mediamtx.webhook_secret', '');
        $provided = (string) $request->header('X-Webhook-Secret', '');

        // Fail-closed: no configured secret means deny everything.
        if ($configured === '' || ! hash_equals($configured, $provided)) {
            abort(403);
        }

        return $next($request);
    }
}
```

Register the alias in `bootstrap/app.php`. Add the import at the top:

```php
use App\Http\Middleware\VerifyMediamtxWebhook;
```

Add to the `$middleware->alias([...])` array:

```php
            'mediamtx.webhook' => VerifyMediamtxWebhook::class,
```

Add a temporary route to `routes/api.php` so the security test can exercise the middleware (replaced by the real controller in Task 6):

```php
Route::post('/mediamtx/event', fn () => response()->noContent())
    ->middleware('mediamtx.webhook');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter MediamtxWebhookSecurityTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/VerifyMediamtxWebhook.php bootstrap/app.php routes/api.php tests/Feature/MediamtxWebhookSecurityTest.php
git commit -m "feat: add mediamtx webhook secret middleware"
```

---

## Task 6: Webhook controller

**Files:**
- Create: `app/Http/Controllers/Api/MediamtxEventController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/MediamtxEventControllerTest.php`

The controller maps the MediaMTX hook `event` to `(kind, phase)`:

| event | kind | phase |
|---|---|---|
| `ready` | publish | started |
| `notready` | publish | ended |
| `read` | read | started |
| `unread` | read | ended |

- [ ] **Step 1: Write the failing test**

```php
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

    private function post(array $payload)
    {
        return $this->withHeaders(['X-Webhook-Secret' => 's3cret'])
            ->postJson('/api/mediamtx/event', $payload);
    }

    public function test_ready_event_returns_204_and_queues_publisher_alert(): void
    {
        Queue::fake();

        $this->post(['event' => 'ready', 'path' => 'live/cam1', 'id' => 'pub-1', 'ip' => '1.2.3.4'])
            ->assertNoContent();

        Queue::assertPushed(SendTelegramMessage::class, function (SendTelegramMessage $job) {
            return str_contains($job->text, 'New publisher on live/cam1');
        });
    }

    public function test_read_event_queues_viewer_alert(): void
    {
        Queue::fake();

        $this->post(['event' => 'read', 'path' => 'live/cam1', 'id' => 'r-1', 'ip' => '9.9.9.9'])
            ->assertNoContent();

        Queue::assertPushed(SendTelegramMessage::class, function (SendTelegramMessage $job) {
            return str_contains($job->text, 'New viewer on live/cam1');
        });
    }

    public function test_unknown_event_returns_204_and_queues_nothing(): void
    {
        Queue::fake();

        $this->post(['event' => 'bogus', 'path' => 'live/cam1'])
            ->assertNoContent();

        Queue::assertNothingPushed();
    }

    public function test_missing_path_returns_422(): void
    {
        $this->post(['event' => 'ready'])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter MediamtxEventControllerTest`
Expected: FAIL — temporary closure route returns 204 but queues nothing, so the `assertPushed` tests fail.

- [ ] **Step 3: Write the controller and wire the route**

Create `app/Http/Controllers/Api/MediamtxEventController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Services\SessionEvent;
use App\Services\SessionNotifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MediamtxEventController
{
    public function __invoke(Request $request, SessionNotifier $notifier): Response
    {
        $data = $request->validate([
            'event' => ['required', 'string'],
            'path' => ['required', 'string'],
            'id' => ['nullable', 'string'],
            'ip' => ['nullable', 'string'],
        ]);

        [$kind, $phase] = match ($data['event']) {
            'ready' => ['publish', 'started'],
            'notready' => ['publish', 'ended'],
            'read' => ['read', 'started'],
            'unread' => ['read', 'ended'],
            default => [null, null],
        };

        if ($kind !== null) {
            $notifier->notify(new SessionEvent(
                kind: $kind,
                sessionId: $data['id'] ?? '',
                path: $data['path'],
                ip: $data['ip'] ?? '',
                state: $kind,
                occurredAt: now()->toIso8601String(),
            ), $phase);
        }

        return response()->noContent();
    }
}
```

Replace the temporary closure route in `routes/api.php` with:

```php
use App\Http\Controllers\Api\MediamtxEventController;

Route::post('/mediamtx/event', MediamtxEventController::class)
    ->middleware('mediamtx.webhook');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter MediamtxEventControllerTest`
Expected: PASS (4 tests). Also re-run `php artisan test --filter MediamtxWebhookSecurityTest` → still PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MediamtxEventController.php routes/api.php tests/Feature/MediamtxEventControllerTest.php
git commit -m "feat: add mediamtx event webhook controller"
```

---

## Task 7: Simplify MediamtxSessionRefresher to syncSnapshot

**Files:**
- Modify: `app/Services/MediamtxSessionRefresher.php`
- Delete: `app/Services/SessionDiff.php`
- Create: `tests/Feature/SessionNotifications/SyncSnapshotTest.php`
- Delete: `tests/Feature/SessionNotifications/RefresherDiffTest.php`, `tests/Feature/SessionNotifications/SessionDiffTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SessionNotifications/SyncSnapshotTest.php`:

```php
<?php

namespace Tests\Feature\SessionNotifications;

use App\Models\MediamtxSession;
use App\Services\MediamtxApi;
use App\Services\MediamtxSessionRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_upserts_live_rows_and_prunes_stale(): void
    {
        // Pre-existing stale row that is NOT in the API response.
        MediamtxSession::create([
            'type' => 'webrtc', 'session_id' => 'old', 'state' => 'read',
            'path' => 'live/old', 'ip' => '5.5.5.5',
            'last_seen_at' => now()->subSeconds(60),
        ]);

        $api = Mockery::mock(MediamtxApi::class);
        $api->shouldReceive('listWebRtcSessions')->andReturn([
            'items' => [
                ['id' => 'live-1', 'state' => 'read', 'path' => 'live/cam1',
                    'query' => '', 'remoteAddr' => '1.2.3.4:5555', 'created' => '2026-06-04T10:00:00Z'],
            ],
        ]);
        $this->app->instance(MediamtxApi::class, $api);

        app(MediamtxSessionRefresher::class)->syncSnapshot();

        $this->assertDatabaseHas('mediamtx_sessions', ['session_id' => 'live-1', 'ip' => '1.2.3.4']);
        $this->assertDatabaseMissing('mediamtx_sessions', ['session_id' => 'old']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter SyncSnapshotTest`
Expected: FAIL — `syncSnapshot()` does not exist.

- [ ] **Step 3: Rewrite the refresher**

Replace the entire body of `app/Services/MediamtxSessionRefresher.php` with the snapshot-only version (no diff, no bytes, no `SessionEvent`/`SessionDiff`):

```php
<?php

namespace App\Services;

use App\Models\MediamtxSession;

class MediamtxSessionRefresher
{
    public function __construct(private readonly MediamtxApi $api) {}

    public function syncSnapshot(): void
    {
        $now = now();

        $items = data_get($this->api->listWebRtcSessions(), 'items', []);

        $rows = collect($items)->map(function ($it) {
            $remote = (string) data_get($it, 'remoteAddr', '');

            return [
                'type' => 'webrtc',
                'session_id' => (string) data_get($it, 'id', ''),
                'state' => (string) data_get($it, 'state', ''),
                'path' => (string) data_get($it, 'path', ''),
                'query' => (string) data_get($it, 'query', ''),
                'remote_addr' => $remote,
                'ip' => $this->extractIp($remote),
                'created' => (string) data_get($it, 'created', ''),
            ];
        })->filter(fn ($r) => $r['session_id'] !== '')->values();

        foreach ($rows as $row) {
            MediamtxSession::updateOrCreate(
                ['type' => $row['type'], 'session_id' => $row['session_id']],
                $row + ['last_seen_at' => $now],
            );
        }

        $stale = (int) config('services.mediamtx.stale_seconds', 20);
        MediamtxSession::where('last_seen_at', '<', now()->subSeconds($stale))->delete();
    }

    private function extractIp(string $remoteAddr): string
    {
        if (str_starts_with($remoteAddr, '[')) {
            $end = strpos($remoteAddr, ']');

            return $end !== false ? substr($remoteAddr, 1, $end - 1) : $remoteAddr;
        }

        if (substr_count($remoteAddr, ':') === 1) {
            return explode(':', $remoteAddr, 2)[0];
        }

        return $remoteAddr;
    }
}
```

Delete `app/Services/SessionDiff.php` and the two obsolete tests:

```bash
git rm app/Services/SessionDiff.php \
  tests/Feature/SessionNotifications/RefresherDiffTest.php \
  tests/Feature/SessionNotifications/SessionDiffTest.php
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter SyncSnapshotTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/MediamtxSessionRefresher.php tests/Feature/SessionNotifications/SyncSnapshotTest.php
git commit -m "refactor: reduce MediamtxSessionRefresher to syncSnapshot"
```

---

## Task 8: Remove the scheduled command and its class

**Files:**
- Modify: `routes/console.php`
- Delete: `app/Console/Commands/RefreshAndNotifySessions.php`, `tests/Feature/SessionNotifications/RefreshAndNotifySessionsCommandTest.php`

- [ ] **Step 1: Edit `routes/console.php`**

Remove the import `use App\Console\Commands\RefreshAndNotifySessions;` and the whole block:

```php
Schedule::command(RefreshAndNotifySessions::class)
    ->everyTenSeconds()
    ->withoutOverlapping()
    ->runInBackground();
```

Leave the two `PruneViewerRequests` / `PruneActivityLog` daily schedules intact.

- [ ] **Step 2: Delete the command and its test**

```bash
git rm app/Console/Commands/RefreshAndNotifySessions.php \
  tests/Feature/SessionNotifications/RefreshAndNotifySessionsCommandTest.php
```

- [ ] **Step 3: Verify no dangling references**

Run: `grep -rn "RefreshAndNotifySessions\|refresh-and-notify\|SessionDiff" app/ routes/ tests/`
Expected: no output.

- [ ] **Step 4: Run the suite**

Run: `php artisan test`
Expected: PASS (no errors about missing `RefreshAndNotifySessions`).

- [ ] **Step 5: Commit**

```bash
git add routes/console.php
git commit -m "refactor: remove 24/7 background session refresh command"
```

---

## Task 9: Sessions page — sync on poll, drop bytes columns

**Files:**
- Modify: `app/Filament/Pages/Sessions.php`
- Modify: `resources/views/filament/pages/sessions.blade.php`

- [ ] **Step 1: Edit `refreshSessions()` in `app/Filament/Pages/Sessions.php`**

Add the import near the other `use` statements:

```php
use App\Services\MediamtxSessionRefresher;
```

Replace the no-op body (lines ~25-32) with:

```php
    public function refreshSessions(): void
    {
        // Sync the snapshot from MediaMTX only while this page is open.
        // No background process runs; alerting is handled by the MediaMTX webhook.
        app(MediamtxSessionRefresher::class)->syncSnapshot();
    }
```

- [ ] **Step 2: Remove the bytes columns**

Delete these two column lines from the `columns([...])` array:

```php
                Tables\Columns\TextColumn::make('bytes_received')->label('Bytes In')->numeric()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('bytes_sent')->label('Bytes Out')->numeric()->toggleable(isToggledHiddenByDefault: true),
```

- [ ] **Step 3: Relax the poll interval**

In `resources/views/filament/pages/sessions.blade.php` change `wire:poll.5s` to `wire:poll.10s`:

```blade
    <div wire:poll.10s="refreshSessions">
```

- [ ] **Step 4: Smoke-test the page renders**

Run: `php artisan test --filter SessionsPageTest` if such a test exists; otherwise verify no syntax error:
Run: `php -l app/Filament/Pages/Sessions.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Pages/Sessions.php resources/views/filament/pages/sessions.blade.php
git commit -m "feat: Sessions page syncs on-demand while open; drop bytes columns"
```

---

## Task 10: Dashboard widget syncs via syncSnapshot

**Files:**
- Modify: `app/Filament/Widgets/SessionsOverview.php`

- [ ] **Step 1: Update the refresh call**

In `getStats()`, replace:

```php
        // Refresh snapshot before counting
        app(MediamtxSessionRefresher::class)->refresh();
```

with:

```php
        // Refresh snapshot before counting — runs only while the dashboard is open.
        app(MediamtxSessionRefresher::class)->syncSnapshot();
```

The existing `use App\Services\MediamtxSessionRefresher;` import stays.

- [ ] **Step 2: Verify**

Run: `php -l app/Filament/Widgets/SessionsOverview.php`
Expected: `No syntax errors detected`.

Run: `grep -rn "->refresh()" app/` 
Expected: no output (the old `refresh()` method no longer exists anywhere).

- [ ] **Step 3: Commit**

```bash
git add app/Filament/Widgets/SessionsOverview.php
git commit -m "refactor: dashboard widget uses syncSnapshot"
```

---

## Task 11: Remove the in-app notification bell

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`

- [ ] **Step 1: Remove the bell + its polling**

Delete these two lines (currently around 34-35):

```php
            ->databaseNotifications()
            ->databaseNotificationsPolling('15s')
```

Leave the rest of the panel chain intact (check the surrounding `->...()` calls still form a valid chain — the line directly above and below must connect).

- [ ] **Step 2: Verify**

Run: `php -l app/Providers/Filament/AdminPanelProvider.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Providers/Filament/AdminPanelProvider.php
git commit -m "refactor: remove in-app notification bell (Telegram-only alerts)"
```

---

## Task 12: Drop bytes columns from model + migration

**Files:**
- Modify: `app/Models/MediamtxSession.php`
- Create: `database/migrations/2026_06_04_000000_drop_bytes_from_mediamtx_sessions.php`

- [ ] **Step 1: Edit the model**

In `app/Models/MediamtxSession.php`, remove `'bytes_received'` and `'bytes_sent'` from `$fillable`, and remove the two `bytes_*` casts. Resulting arrays:

```php
    protected $fillable = [
        'type', 'session_id', 'state', 'path', 'query', 'remote_addr', 'ip', 'created',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];
```

- [ ] **Step 2: Create the migration**

`database/migrations/2026_06_04_000000_drop_bytes_from_mediamtx_sessions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mediamtx_sessions', function (Blueprint $table) {
            $table->dropColumn(['bytes_received', 'bytes_sent']);
        });
    }

    public function down(): void
    {
        Schema::table('mediamtx_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('bytes_received')->default(0);
            $table->unsignedBigInteger('bytes_sent')->default(0);
        });
    }
};
```

- [ ] **Step 3: Run the migration against the test DB to confirm up/down work**

Run: `php artisan migrate --pretend` to confirm it is picked up, then run the full suite (tests run migrations on the in-memory DB):
Run: `php artisan test --filter SyncSnapshotTest`
Expected: PASS (table has no bytes columns and sync still works).

- [ ] **Step 4: Confirm no dangling bytes references**

Run: `grep -rn "bytes_received\|bytes_sent\|bytesReceived\|bytesSent" app/ database/migrations/2026_06_04_000000_drop_bytes_from_mediamtx_sessions.php`
Expected: only matches inside the new drop migration's `down()` (the restore) — nothing in `app/`.

- [ ] **Step 5: Commit**

```bash
git add app/Models/MediamtxSession.php database/migrations/2026_06_04_000000_drop_bytes_from_mediamtx_sessions.php
git commit -m "feat: drop bytes_received/bytes_sent from mediamtx_sessions"
```

---

## Task 13: Operator docs for MediaMTX hooks

**Files:**
- Create: `docs/mediamtx-hooks.md`

- [ ] **Step 1: Write the hook documentation**

Create `docs/mediamtx-hooks.md`:

````markdown
# MediaMTX → Telegram alert hooks

These hooks make MediaMTX notify the Laravel app the instant a stream or viewer
starts/stops. No background polling is involved.

## 1. Laravel `.env`

```
TELEGRAM_ALERTS_ENABLED=true
TELEGRAM_BOT_TOKEN=<token from @BotFather>
TELEGRAM_CHAT_ID=<your chat or channel id>
MEDIAMTX_WEBHOOK_SECRET=<a long random string>
```

Create the bot with @BotFather; get your chat id by messaging the bot then reading
`https://api.telegram.org/bot<token>/getUpdates`.

## 2. `mediamtx.yml`

Under `pathDefaults:` (applies to all paths), add — replacing `SECRET` with the same
value as `MEDIAMTX_WEBHOOK_SECRET`, and the URL host/port with where Laravel listens
(same VM = 127.0.0.1):

```yaml
pathDefaults:
  runOnReady: >
    curl -fsS -m 4 -X POST http://127.0.0.1:8000/api/mediamtx/event
    -H "X-Webhook-Secret: SECRET"
    --data-urlencode "event=ready"
    --data-urlencode "path=$MTX_PATH"
    --data-urlencode "id=$MTX_SOURCE_ID"
  runOnNotReady: >
    curl -fsS -m 4 -X POST http://127.0.0.1:8000/api/mediamtx/event
    -H "X-Webhook-Secret: SECRET"
    --data-urlencode "event=notready"
    --data-urlencode "path=$MTX_PATH"
    --data-urlencode "id=$MTX_SOURCE_ID"
  runOnRead: >
    curl -fsS -m 4 -X POST http://127.0.0.1:8000/api/mediamtx/event
    -H "X-Webhook-Secret: SECRET"
    --data-urlencode "event=read"
    --data-urlencode "path=$MTX_PATH"
    --data-urlencode "id=$MTX_READER_ID"
  runOnUnread: >
    curl -fsS -m 4 -X POST http://127.0.0.1:8000/api/mediamtx/event
    -H "X-Webhook-Secret: SECRET"
    --data-urlencode "event=unread"
    --data-urlencode "path=$MTX_PATH"
    --data-urlencode "id=$MTX_READER_ID"
```

> Note: the exact env vars MediaMTX exposes to hooks vary by version. Confirm against
> your build's docs; `MTX_PATH` is always present. The client IP is not always exposed
> to read hooks — if absent, alerts show "IP unknown" (acceptable). To include it later,
> the webhook can resolve the IP from the MediaMTX API by reader id.

Restart MediaMTX after editing. Ensure a Laravel queue worker is running so Telegram
sends are processed.
````

- [ ] **Step 2: Commit**

```bash
git add docs/mediamtx-hooks.md
git commit -m "docs: mediamtx hook configuration for telegram alerts"
```

---

## Task 14: Full suite + style + final verification

- [ ] **Step 1: Run the whole test suite**

Run: `php artisan test`
Expected: PASS, no skipped session-notification failures.

- [ ] **Step 2: Confirm no dead references remain**

Run: `grep -rn "sendToDatabase\|SessionDiff\|->refresh()\|RefreshAndNotifySessions\|databaseNotifications\|bytes_received\|bytes_sent" app/ routes/ resources/`
Expected: no output.

- [ ] **Step 3: Run Pint**

Run: `./vendor/bin/pint`
Expected: style clean (auto-fixes formatting).

- [ ] **Step 4: Commit any Pint changes**

```bash
git add -A
git commit -m "style: pint" || echo "nothing to format"
```

---

## Self-Review notes (for the implementer)

- **`SessionEvent` is retained** and is the shared DTO between the webhook controller and
  `SessionNotifier`. Do not delete it (only `SessionDiff` is deleted).
- **Method name is `syncSnapshot()`** everywhere (refresher, Sessions page, widget). The old
  `refresh()` must have zero references after Task 10.
- **Telegram delivery is queued** (`SendTelegramMessage`), so under `QUEUE_CONNECTION=sync`
  in tests it runs inline — use `Queue::fake()` to assert dispatch, `Http::fake()` only in
  `TelegramNotifierTest`.
- **Fail-closed security:** an unset `MEDIAMTX_WEBHOOK_SECRET` denies all webhook requests
  (mirrors the existing `AllowMediamtxOnly` posture).
