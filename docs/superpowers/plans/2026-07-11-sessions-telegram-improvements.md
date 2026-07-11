# Sessions & Telegram Improvements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make MoQ sessions visible + alertable on the Live Sessions page, let the page kick any protocol, and forward a curated set of `security`-channel activity events to Telegram.

**Architecture:** Extend `MediamtxApi` with list/kick methods for every MediaMTX v1.19.1 protocol and a `kick($type,$id)` dispatcher; have `MediamtxSessionRefresher` merge all-protocol snapshots into the `mediamtx_sessions` table; route the Sessions page kick action by row type; and add a single `SecurityAlertListener` on Activity creation that forwards allowlisted security events to Telegram via the existing `SendTelegramMessage` job. Shared HTML-escaping and flood-cap logic is extracted into `App\Support\TelegramAlert`.

**Tech Stack:** Laravel 12 / PHP 8.2, Filament v3, Spatie Activitylog, Guzzle via `Illuminate\Support\Facades\Http`, PHPUnit (SQLite `:memory:`).

## Global Constraints

- Branch: `develop` is the single source of truth. Do **not** touch `develop-new`.
- MediaMTX target: **v1.19.1**. Endpoint set is fixed (see task 1). HLS has **no** kick endpoint.
- Tests: SQLite in-memory, `Http::fake()` for MediaMTX, `Queue::fake()` + `Queue::assertPushed(SendTelegramMessage::class, ...)` for Telegram assertions.
- Telegram messages are sent with `parse_mode=HTML`; every operator/user-controlled field MUST be HTML-escaped before interpolation.
- MediaMTX reader/source `type` strings: `webRTCSession`, `moqSession`, `rtmpConn`, `rtspSession`, `srtConn`, `hlsMuxer`.
- Session row `type` values stored in DB: `webrtc`, `moq`, `rtmp`, `rtsp`, `srt`.
- Run the full suite with `php artisan test`; single file with `php artisan test tests/Feature/File.php`.
- Run `./vendor/bin/pint` before each commit.
- Document per CLAUDE.md: update `docs/` + `README.md` (final task).

---

### Task 1: MediamtxApi — list & kick for all protocols

**Files:**
- Modify: `app/Services/MediamtxApi.php`
- Test: `tests/Feature/MediamtxApiTest.php`

**Interfaces:**
- Consumes: existing `http()` (private), `listWebRtcSessions()`, `kickWebRtc()`.
- Produces:
  - `listMoqSessions(): array`, `listRtmpConns(): array`, `listRtspSessions(): array`, `listSrtConns(): array`
  - `kick(string $type, string $id): void` — routes by DB type (`webrtc|moq|rtmp|rtsp|srt`); throws `InvalidArgumentException` for `hls`/unknown.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/MediamtxApiTest.php` (top: ensure `use App\Services\MediamtxApi;` and `use Illuminate\Support\Facades\Http;` present):

```php
public function test_list_moq_sessions_returns_decoded_items(): void
{
    Http::fake(['*/v3/moqsessions/list' => Http::response(['items' => [
        ['id' => 'm-1', 'path' => 'live/cam1', 'state' => 'read', 'remoteAddr' => '5.6.7.8:1111'],
    ]], 200)]);

    $items = data_get(app(MediamtxApi::class)->listMoqSessions(), 'items');

    $this->assertSame('m-1', $items[0]['id']);
}

public function test_kick_routes_to_protocol_endpoint(): void
{
    Http::fake(['*' => Http::response('', 200)]);
    $api = app(MediamtxApi::class);

    $api->kick('webrtc', 'w1');
    $api->kick('moq', 'm1');
    $api->kick('rtmp', 'r1');
    $api->kick('rtsp', 's1');
    $api->kick('srt', 't1');

    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v3/webrtcsessions/kick/w1'));
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v3/moqsessions/kick/m1'));
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v3/rtmpconns/kick/r1'));
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v3/rtspsessions/kick/s1'));
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v3/srtconns/kick/t1'));
}

public function test_kick_rejects_unkickable_type(): void
{
    $this->expectException(\InvalidArgumentException::class);
    app(MediamtxApi::class)->kick('hls', 'x1');
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/MediamtxApiTest.php`
Expected: FAIL — `Call to undefined method ...::listMoqSessions()` / `kick()`.

- [ ] **Step 3: Implement the methods**

In `app/Services/MediamtxApi.php`, add after `listPaths()`:

```php
public function listMoqSessions(): array
{
    return $this->http()->get('/v3/moqsessions/list')->throw()->json();
}

public function listRtmpConns(): array
{
    return $this->http()->get('/v3/rtmpconns/list')->throw()->json();
}

public function listRtspSessions(): array
{
    return $this->http()->get('/v3/rtspsessions/list')->throw()->json();
}

public function listSrtConns(): array
{
    return $this->http()->get('/v3/srtconns/list')->throw()->json();
}
```

Add after `kickWebRtc()`:

```php
/**
 * Kick a live session by its stored DB type. HLS has no kick endpoint.
 */
public function kick(string $type, string $id): void
{
    $endpoint = match ($type) {
        'webrtc' => "/v3/webrtcsessions/kick/{$id}",
        'moq' => "/v3/moqsessions/kick/{$id}",
        'rtmp' => "/v3/rtmpconns/kick/{$id}",
        'rtsp' => "/v3/rtspsessions/kick/{$id}",
        'srt' => "/v3/srtconns/kick/{$id}",
        default => throw new \InvalidArgumentException("Cannot kick session type: {$type}"),
    };

    $this->http()->post($endpoint)->throw();
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/MediamtxApiTest.php`
Expected: PASS.

- [ ] **Step 5: Lint & commit**

```bash
./vendor/bin/pint app/Services/MediamtxApi.php tests/Feature/MediamtxApiTest.php
git add app/Services/MediamtxApi.php tests/Feature/MediamtxApiTest.php
git commit -m "feat(mediamtx): add MoQ/RTMP/RTSP/SRT list + kick-by-type dispatcher"
```

---

### Task 2: MoQ webhook alerts — type-aware enrichment + MoQ label

**Files:**
- Modify: `app/Services/MediamtxApi.php` (change `findSession` signature)
- Modify: `app/Http/Controllers/Api/MediamtxEventController.php` (pass `type`)
- Modify: `app/Services/SessionNotifier.php` (`protocolLabel` MoQ case)
- Test: `tests/Feature/MediamtxApiTest.php`, `tests/Feature/MediamtxEventControllerTest.php`

**Interfaces:**
- Consumes: `listWebRtcSessions()`, `listMoqSessions()` (task 1), `clientIp()`.
- Produces: `findSession(string $id, string $type = ''): array` — queries the MoQ list when `$type === 'moqSession'`, else the WebRTC list. Return shape unchanged: `['ip' => ..., 'userAgent' => ...]`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/MediamtxApiTest.php`:

```php
public function test_find_session_enriches_moq_by_type(): void
{
    Http::fake(['*/v3/moqsessions/list' => Http::response(['items' => [
        ['id' => 'm-1', 'remoteAddr' => '5.6.7.8:1111', 'userAgent' => ''],
    ]], 200)]);

    $result = app(MediamtxApi::class)->findSession('m-1', 'moqSession');

    $this->assertSame('5.6.7.8', $result['ip']);
}
```

Add to `tests/Feature/MediamtxEventControllerTest.php`:

```php
public function test_moq_read_event_labels_moq_and_enriches_ip(): void
{
    Queue::fake();
    Http::fake(['*/v3/moqsessions/list' => Http::response(['items' => [
        ['id' => 'mr-1', 'remoteAddr' => '5.6.7.8:2222', 'userAgent' => ''],
    ]], 200)]);

    $this->postEvent(['event' => 'read', 'path' => 'live/cam1', 'id' => 'mr-1', 'type' => 'moqSession'])
        ->assertNoContent();

    Queue::assertPushed(SendTelegramMessage::class, function (SendTelegramMessage $job) {
        return str_contains($job->text, 'Viewer started')
            && str_contains($job->text, 'MoQ')
            && str_contains($job->text, '5.6.7.8');
    });
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/MediamtxApiTest.php tests/Feature/MediamtxEventControllerTest.php`
Expected: FAIL — MoQ enrichment/label not implemented (IP empty / "MoQ" missing).

- [ ] **Step 3: Implement**

In `app/Services/MediamtxApi.php`, replace the `findSession` body:

```php
public function findSession(string $id, string $type = ''): array
{
    $empty = ['ip' => '', 'userAgent' => ''];

    if ($id === '') {
        return $empty;
    }

    try {
        $items = $type === 'moqSession'
            ? data_get($this->listMoqSessions(), 'items', [])
            : data_get($this->listWebRtcSessions(), 'items', []);
    } catch (Throwable $e) {
        return $empty;
    }

    foreach ($items as $it) {
        if ((string) data_get($it, 'id', '') === $id) {
            return [
                'ip' => self::clientIp($it),
                'userAgent' => (string) data_get($it, 'userAgent', ''),
            ];
        }
    }

    return $empty;
}
```

In `app/Http/Controllers/Api/MediamtxEventController.php`, change the enrichment call (line ~44) to pass the type:

```php
$session = $api->findSession($id, $data['type'] ?? '');
```

In `app/Services/SessionNotifier.php`, add a MoQ case to `protocolLabel()` match (before `default`):

```php
'moqSession' => 'MoQ',
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/MediamtxApiTest.php tests/Feature/MediamtxEventControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Lint & commit**

```bash
./vendor/bin/pint app/Services/MediamtxApi.php app/Http/Controllers/Api/MediamtxEventController.php app/Services/SessionNotifier.php tests/Feature/MediamtxApiTest.php tests/Feature/MediamtxEventControllerTest.php
git add app/Services/MediamtxApi.php app/Http/Controllers/Api/MediamtxEventController.php app/Services/SessionNotifier.php tests/Feature/MediamtxApiTest.php tests/Feature/MediamtxEventControllerTest.php
git commit -m "feat(alerts): enrich + label MoQ read/unread session events"
```

---

### Task 3: MediamtxSessionRefresher — merge all protocols

**Files:**
- Modify: `app/Services/MediamtxSessionRefresher.php`
- Test: `tests/Feature/SessionNotifications/SyncSnapshotTest.php`

**Interfaces:**
- Consumes: `listWebRtcSessions/listMoqSessions/listRtmpConns/listRtspSessions/listSrtConns` (task 1), `MediamtxApi::clientIp()`.
- Produces: `mediamtx_sessions` rows tagged `type` ∈ {`webrtc`,`moq`,`rtmp`,`rtsp`,`srt`}. A failing list endpoint is skipped, not fatal.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/SessionNotifications/SyncSnapshotTest.php` (ensure `use App\Models\MediamtxSession;`, `use App\Services\MediamtxSessionRefresher;`, `use Illuminate\Support\Facades\Http;`):

```php
public function test_sync_snapshot_merges_all_protocols_and_survives_one_failure(): void
{
    Http::fake([
        '*/v3/webrtcsessions/list' => Http::response(['items' => [
            ['id' => 'w-1', 'path' => 'live/cam1', 'state' => 'read', 'remoteAddr' => '1.1.1.1:1', 'userAgent' => 'Mozilla'],
        ]], 200),
        '*/v3/moqsessions/list' => Http::response(['items' => [
            ['id' => 'm-1', 'path' => 'live/cam1', 'state' => 'read', 'remoteAddr' => '2.2.2.2:2'],
        ]], 200),
        '*/v3/rtmpconns/list' => Http::response(['items' => [
            ['id' => 'r-1', 'path' => 'live/cam2', 'state' => 'publish', 'remoteAddr' => '3.3.3.3:3'],
        ]], 200),
        '*/v3/rtspsessions/list' => Http::response('boom', 500), // one endpoint down
        '*/v3/srtconns/list' => Http::response(['items' => [
            ['id' => 't-1', 'path' => 'live/cam3', 'state' => 'publish', 'remoteAddr' => '4.4.4.4:4'],
        ]], 200),
    ]);

    app(MediamtxSessionRefresher::class)->syncSnapshot();

    $this->assertSame('webrtc', MediamtxSession::where('session_id', 'w-1')->value('type'));
    $this->assertSame('moq', MediamtxSession::where('session_id', 'm-1')->value('type'));
    $this->assertSame('rtmp', MediamtxSession::where('session_id', 'r-1')->value('type'));
    $this->assertSame('srt', MediamtxSession::where('session_id', 't-1')->value('type'));
    $this->assertSame('2.2.2.2', MediamtxSession::where('session_id', 'm-1')->value('ip'));
    // rtsp endpoint failed → no rows, but the sync still completed for the others
    $this->assertSame(4, MediamtxSession::count());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/SessionNotifications/SyncSnapshotTest.php`
Expected: FAIL — only `webrtc` rows exist (count 1, no moq/rtmp/srt).

- [ ] **Step 3: Implement**

Replace the body of `syncSnapshot()` in `app/Services/MediamtxSessionRefresher.php`:

```php
public function syncSnapshot(): void
{
    $now = now();

    $sources = [
        'webrtc' => fn () => $this->api->listWebRtcSessions(),
        'moq' => fn () => $this->api->listMoqSessions(),
        'rtmp' => fn () => $this->api->listRtmpConns(),
        'rtsp' => fn () => $this->api->listRtspSessions(),
        'srt' => fn () => $this->api->listSrtConns(),
    ];

    foreach ($sources as $type => $fetch) {
        try {
            $items = data_get($fetch(), 'items', []);
        } catch (\Throwable $e) {
            // One protocol being unreachable must not blank the whole page.
            \Illuminate\Support\Facades\Log::warning('Session snapshot fetch failed', [
                'type' => $type, 'error' => $e->getMessage(),
            ]);

            continue;
        }

        foreach ($items as $it) {
            $sessionId = (string) data_get($it, 'id', '');
            if ($sessionId === '') {
                continue;
            }

            MediamtxSession::updateOrCreate(
                ['type' => $type, 'session_id' => $sessionId],
                [
                    'state' => (string) data_get($it, 'state', ''),
                    'path' => (string) data_get($it, 'path', ''),
                    'query' => (string) data_get($it, 'query', ''),
                    'remote_addr' => (string) data_get($it, 'remoteAddr', ''),
                    'ip' => MediamtxApi::clientIp($it),
                    'created' => (string) data_get($it, 'created', ''),
                    'user_agent' => (string) data_get($it, 'userAgent', '') ?: null,
                    'last_seen_at' => $now,
                ],
            );
        }
    }

    $stale = (int) config('services.mediamtx.stale_seconds', 20);
    MediamtxSession::where('last_seen_at', '<', now()->subSeconds($stale))->delete();
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/SessionNotifications/SyncSnapshotTest.php`
Expected: PASS. Then run the pre-existing tests in that file to confirm no regression (same command runs the whole file).

- [ ] **Step 5: Lint & commit**

```bash
./vendor/bin/pint app/Services/MediamtxSessionRefresher.php tests/Feature/SessionNotifications/SyncSnapshotTest.php
git add app/Services/MediamtxSessionRefresher.php tests/Feature/SessionNotifications/SyncSnapshotTest.php
git commit -m "feat(sessions): snapshot all protocols (webrtc/moq/rtmp/rtsp/srt)"
```

---

### Task 4: Sessions page — kick by type, hide for HLS

**Files:**
- Modify: `app/Filament/Pages/Sessions.php`
- Test: `tests/Feature/SessionsPageKickTest.php` (create)

**Interfaces:**
- Consumes: `MediamtxApi::kick($type, $id)` (task 1).
- Produces: kick action routed by `$record->type`; hidden when `type === 'hls'`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SessionsPageKickTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Pages\Sessions;
use App\Models\MediamtxSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SessionsPageKickTest extends TestCase
{
    use RefreshDatabase;

    public function test_kick_action_routes_to_moq_endpoint(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        Role::findOrCreate('super-admin');
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $session = MediamtxSession::create([
            'type' => 'moq', 'session_id' => 'm-42', 'state' => 'read',
            'path' => 'live/cam1', 'ip' => '5.6.7.8', 'last_seen_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(Sessions::class)
            ->callTableAction('kick', $session);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v3/moqsessions/kick/m-42'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/SessionsPageKickTest.php`
Expected: FAIL — action still calls `kickWebRtc`, so the MoQ endpoint is never hit.

- [ ] **Step 3: Implement**

In `app/Filament/Pages/Sessions.php`, change the `kick` action's `->action(...)` call from `kickWebRtc($record->session_id)` to the type dispatcher, and add a `->visible()` guard hiding it for HLS. Replace the kick `Action::make('kick')` block with:

```php
Action::make('kick')
    ->label('Kick')
    ->icon('heroicon-o-hand-raised')
    ->requiresConfirmation()
    ->action(function (MediamtxSession $record) {
        app(MediamtxApi::class)->kick($record->type, $record->session_id);

        activity('security')
            ->causedBy(auth()->user())
            ->withProperties([
                'type' => $record->type,
                'id' => $record->session_id,
                'path' => $record->path,
                'remote' => $record->remote_addr,
            ])
            ->log('session.kicked');

        Notification::make()->title('Session kicked')->success()->send();
    })
    ->visible(fn (MediamtxSession $record) => $record->type !== 'hls'
        && (auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false)),
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/SessionsPageKickTest.php`
Expected: PASS.

- [ ] **Step 5: Lint & commit**

```bash
./vendor/bin/pint app/Filament/Pages/Sessions.php tests/Feature/SessionsPageKickTest.php
git add app/Filament/Pages/Sessions.php tests/Feature/SessionsPageKickTest.php
git commit -m "feat(sessions): kick by protocol type; hide kick for HLS"
```

---

### Task 5: TelegramAlert shared support (escape + flood cap)

**Files:**
- Create: `app/Support/TelegramAlert.php`
- Modify: `app/Services/SessionNotifier.php` (use shared helpers; behavior unchanged)
- Test: `tests/Unit/TelegramAlertTest.php` (create)

**Interfaces:**
- Produces:
  - `TelegramAlert::esc(string $value): string` — HTML-escapes with `ENT_QUOTES | ENT_HTML5`, UTF-8.
  - `TelegramAlert::floodCap(string $bucket, int $cap, int $ttl = 65): bool` — per-minute cap; returns `false` once `> $cap` calls occur in the current minute.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/TelegramAlertTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\TelegramAlert;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TelegramAlertTest extends TestCase
{
    public function test_esc_encodes_html_special_chars(): void
    {
        $this->assertSame('&lt;b&gt;&amp;', TelegramAlert::esc('<b>&'));
    }

    public function test_flood_cap_blocks_after_cap(): void
    {
        Cache::flush();

        $results = [];
        for ($i = 0; $i < 4; $i++) {
            $results[] = TelegramAlert::floodCap('test', 3);
        }

        $this->assertSame([true, true, true, false], $results);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/TelegramAlertTest.php`
Expected: FAIL — class `App\Support\TelegramAlert` not found.

- [ ] **Step 3: Implement the support class**

Create `app/Support/TelegramAlert.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class TelegramAlert
{
    /**
     * HTML-escape an operator/user-controlled field for parse_mode=HTML messages.
     */
    public static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Per-minute flood cap. Returns false once more than $cap calls have occurred
     * for $bucket within the current wall-clock minute.
     */
    public static function floodCap(string $bucket, int $cap, int $ttl = 65): bool
    {
        $key = "notify:flood:{$bucket}:".now()->format('YmdHi');

        Cache::add($key, 0, $ttl);

        return Cache::increment($key) <= $cap;
    }
}
```

- [ ] **Step 4: Refactor SessionNotifier to use the shared escape helper**

In `app/Services/SessionNotifier.php`, add `use App\Support\TelegramAlert;` and replace each `htmlspecialchars($x, ENT_QUOTES | ENT_HTML5, 'UTF-8')` call with `TelegramAlert::esc($x)`. Leave `passesReadFloodCap()`/`passesDedupe()` as-is (their behavior is covered by existing tests; the generic `floodCap` is for task 6). Do not change any message text.

- [ ] **Step 5: Run tests to verify everything passes**

Run: `php artisan test tests/Unit/TelegramAlertTest.php tests/Feature/SessionNotifications/SessionNotifierTest.php`
Expected: PASS (new unit test + unchanged SessionNotifier behavior).

- [ ] **Step 6: Lint & commit**

```bash
./vendor/bin/pint app/Support/TelegramAlert.php app/Services/SessionNotifier.php tests/Unit/TelegramAlertTest.php
git add app/Support/TelegramAlert.php app/Services/SessionNotifier.php tests/Unit/TelegramAlertTest.php
git commit -m "refactor(alerts): extract shared TelegramAlert esc + floodCap helpers"
```

---

### Task 6: SecurityAlertListener — forward curated security events to Telegram

**Files:**
- Create: `app/Listeners/SecurityAlertListener.php`
- Modify: `config/services.php` (telegram: `security_alerts_enabled`, `security_events`)
- Modify: `app/Providers/AppServiceProvider.php` (register Activity `created` hook)
- Test: `tests/Feature/SecurityAlertListenerTest.php` (create)

**Interfaces:**
- Consumes: `TelegramAlert::esc/floodCap` (task 5), `SendTelegramMessage` job, Spatie `Activity` model.
- Produces: `SecurityAlertListener::handle(\Spatie\Activitylog\Models\Activity $activity): void` — dispatches `SendTelegramMessage` for allowlisted `security`-channel events only.

- [ ] **Step 1: Add config (needed by the test)**

In `config/services.php`, extend the `telegram` block:

```php
'telegram' => [
    'enabled' => (bool) env('TELEGRAM_ALERTS_ENABLED', false),
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'chat_id' => env('TELEGRAM_CHAT_ID'),
    'timeout' => (int) env('TELEGRAM_TIMEOUT', 5),
    'security_alerts_enabled' => (bool) env('TELEGRAM_SECURITY_ALERTS_ENABLED', true),
    'security_events' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'TELEGRAM_SECURITY_EVENTS',
        'session.kicked,ip.blocked.from_session,ip.blocked,ip.unblocked,token.read.denied.blocked_ip,token.read.denied.bad_referer'
    ))))),
],
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/SecurityAlertListenerTest.php`:

```php
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
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/SecurityAlertListenerTest.php`
Expected: FAIL — no dispatch (listener not wired).

- [ ] **Step 4: Implement the listener**

Create `app/Listeners/SecurityAlertListener.php`:

```php
<?php

namespace App\Listeners;

use App\Jobs\SendTelegramMessage;
use App\Support\TelegramAlert;
use Carbon\Carbon;
use Spatie\Activitylog\Models\Activity;

class SecurityAlertListener
{
    private const FLOOD_CAP_PER_MINUTE = 30;

    public function handle(Activity $activity): void
    {
        if (! config('services.telegram.enabled') || ! config('services.telegram.security_alerts_enabled')) {
            return;
        }

        if ($activity->log_name !== 'security') {
            return;
        }

        $description = (string) $activity->description;
        if (! in_array($description, (array) config('services.telegram.security_events', []), true)) {
            return;
        }

        if (! TelegramAlert::floodCap('security', self::FLOOD_CAP_PER_MINUTE)) {
            return;
        }

        SendTelegramMessage::dispatch($this->format($activity, $description));
    }

    private function format(Activity $activity, string $description): string
    {
        [$emoji, $title] = match ($description) {
            'session.kicked' => ['🚫', 'Session kicked'],
            'ip.blocked.from_session' => ['⛔', 'IP blocked (from session)'],
            'ip.blocked' => ['⛔', 'IP blocked'],
            'ip.unblocked' => ['✅', 'IP unblocked'],
            'token.read.denied.blocked_ip' => ['🛑', 'Read denied — blocked IP'],
            'token.read.denied.bad_referer' => ['🛑', 'Read denied — bad referrer'],
            default => ['🔔', $description],
        };

        $props = $activity->properties ?? collect();
        $get = fn (string $k) => (string) data_get($props, $k, '');

        $lines = ["{$emoji} <b>".TelegramAlert::esc($title).'</b>', ''];

        foreach ([
            'IP' => $get('ip'),
            'Stream' => $get('path'),
            'Referer' => $get('referer'),
            'Reason' => $get('reason'),
        ] as $label => $value) {
            if ($value !== '') {
                $lines[] = "<b>{$label}:</b> <code>".TelegramAlert::esc($value).'</code>';
            }
        }

        $causer = $activity->causer;
        if ($causer && isset($causer->username)) {
            $lines[] = '<b>By:</b> '.TelegramAlert::esc((string) $causer->username);
        }

        $when = Carbon::parse($activity->created_at)->timezone('Asia/Manila')->format('M d, Y h:i:s A');
        $lines[] = "🕒 <i>{$when}</i>";

        return implode("\n", $lines);
    }
}
```

- [ ] **Step 5: Register the Activity hook**

In `app/Providers/AppServiceProvider.php`, inside `boot()`, add:

```php
\Spatie\Activitylog\Models\Activity::created(function (\Spatie\Activitylog\Models\Activity $activity) {
    app(\App\Listeners\SecurityAlertListener::class)->handle($activity);
});
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/SecurityAlertListenerTest.php`
Expected: PASS (all three cases).

- [ ] **Step 7: Lint & commit**

```bash
./vendor/bin/pint app/Listeners/SecurityAlertListener.php app/Providers/AppServiceProvider.php config/services.php tests/Feature/SecurityAlertListenerTest.php
git add app/Listeners/SecurityAlertListener.php app/Providers/AppServiceProvider.php config/services.php tests/Feature/SecurityAlertListenerTest.php
git commit -m "feat(alerts): forward curated security activity events to Telegram"
```

---

### Task 7: Config docs, env, README, guide + full suite

**Files:**
- Modify: `.env.example`
- Create: `docs/sessions-telegram-improvements.md`
- Modify: `README.md`

**Interfaces:** none (documentation + env).

- [ ] **Step 1: Add env keys**

In `.env.example`, under the existing Telegram section, add:

```
TELEGRAM_SECURITY_ALERTS_ENABLED=true
TELEGRAM_SECURITY_EVENTS=session.kicked,ip.blocked.from_session,ip.blocked,ip.unblocked,token.read.denied.blocked_ip,token.read.denied.bad_referer
```

- [ ] **Step 2: Write the guide**

Create `docs/sessions-telegram-improvements.md` covering: **Goal** (MoQ visibility + all-protocol kick + security Telegram alerts); **Design** (link the spec; note the MediaMTX v1.19.1 endpoint table and the DB `type` values); **Verify** (`php artisan test`, and the manual check below); **Deploy** (git-push to server, run migrations if any — there are none, set the two new env vars, restart the queue worker so `SendTelegramMessage` picks up new config). Note the curated allowlist and how to extend it via `TELEGRAM_SECURITY_EVENTS`.

- [ ] **Step 3: Update README**

In `README.md`: update the Live Sessions description to say it now lists **all protocols** (WebRTC/MoQ/RTMP/RTSP/SRT) and can kick any of them (except HLS); add a **Telegram security alerts** subsection describing the curated allowlist; add the two new env vars to the env-var reference; keep the table-of-contents in sync.

- [ ] **Step 4: Run the full suite + Pint**

Run:
```bash
./vendor/bin/pint
php artisan test
```
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add .env.example docs/sessions-telegram-improvements.md README.md
git commit -m "docs: document all-protocol sessions + Telegram security alerts"
```

- [ ] **Step 6: Manual verification (staging/prod, optional but recommended)**

With MediaMTX reachable: open Live Sessions, start a MoQ playback via `/moq?stream=<path>` → row appears with type `moq`; click Kick → session drops. Trigger a blocked-IP read (`POST /api/read` from a blocked IP) → a "Read denied — blocked IP" Telegram message arrives.

---

## Self-Review

**Spec coverage:**
- MoQ in API → Task 1 (`listMoqSessions`, kick). ✓
- MoQ on Sessions page → Task 3 (refresher). ✓
- MoQ Telegram notif → Task 2 (enrichment + label; read/unread already flows). ✓
- Kick all protocols → Task 1 (dispatcher) + Task 4 (page routing, HLS hidden). ✓
- Security Telegram alerts, curated allowlist, single integration point → Task 6. ✓
- Shared helpers refactor → Task 5. ✓
- Config/env/docs/README → Tasks 6 (config) + 7 (env/docs). ✓
- All-protocol listing (D1) → Task 3. ✓  Curated allowlist (D2) → Task 6. ✓

**Placeholder scan:** none — every code step has concrete code.

**Type consistency:** `kick($type,$id)` (task 1) used verbatim in tasks 3-conceptual/4; `findSession($id,$type='')` (task 2) matches the controller call; `TelegramAlert::esc/floodCap` (task 5) signatures match task 6 usage; DB `type` values (`webrtc/moq/rtmp/rtsp/srt`) consistent across tasks 3, 4, 6.
