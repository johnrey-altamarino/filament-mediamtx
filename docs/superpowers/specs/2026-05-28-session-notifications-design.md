# Session Start/End Notifications — Design

**Date:** 2026-05-28
**Status:** Draft

## Goal

Notify admins (via Filament's database/bell notifications) when WebRTC publish or read sessions start or end on the MediaMTX server, so they can react to publisher disconnects and observe viewer activity without keeping the Sessions page open.

## Scope

- In scope: detecting started/ended sessions, deduping noisy read events, fanning out database notifications to admin users, enabling Filament database notifications on the admin panel.
- Out of scope: email/webhook/Slack delivery, push notifications, per-user opt-in preferences, notifications for non-WebRTC protocols.

## User-facing behaviour

- Notifications fire as four event kinds:
  - **Publisher started** on `{path}` (color: success)
  - **Publisher ended** on `{path}` (color: gray)
  - **New viewer** on `{path}` (color: success)
  - **Viewer ended** on `{path}` (color: gray)
- Title: `{Verb} on {path}` (e.g. `New viewer on live/cam1`). Body: `IP {ip} · {relative time}`.
- A single `View sessions` action on the notification routes to the Sessions page.
- Recipients: users holding either the `super-admin` or `moderator` role. Users with only `monitoring` do **not** receive notifications.
- Read (viewer) events from the same `(ip, path)` are suppressed within a 5-minute window.
- A global flood cap of 20 `read.started` notifications per wall-clock minute prevents spam during traffic spikes; excess events are dropped with a single throttle log line per minute.

## Architecture

```
Scheduler (every 10s)
        │
        ▼
Console: sessions:refresh-and-notify
        │
        ▼
MediamtxSessionRefresher::refresh(): SessionDiff
   - fetch API items
   - diff vs DB session_ids (started / ended)
   - upsert active rows
   - delete stale rows
        │
        ▼
SessionNotifier::dispatch(SessionDiff)
   - cache-based dedupe per event kind
   - flood cap for read.started
   - sendToDatabase to admin recipients
        │
        ▼
Filament bell widget (admin panel)
```

The Sessions page poll (`wire:poll.5s="refreshSessions"`) is unchanged and still calls the refresher for live UI updates. Notifications run independently from the scheduler so they fire even when no admin has the panel open.

## Components

### `MediamtxSessionRefresher` (modified)

- Signature: `refresh(): SessionDiff`.
- Steps:
  1. Fetch `items` from `MediamtxApi::listWebRtcSessions()`.
  2. Read existing `session_id`s and minimal columns from `mediamtx_sessions` where `type = 'webrtc'`.
  3. Compute `started = api_ids − db_ids` and `ended = db_ids − api_ids`. Capture the relevant row data (session_id, path, ip, state, created/last_seen_at) for each.
  4. Upsert active rows (existing behaviour).
  5. Delete stale rows older than `services.mediamtx.stale_seconds` (existing behaviour).
  6. Return a `SessionDiff` DTO containing two arrays: `started` and `ended`. Each entry is shaped as `SessionEvent { kind: 'publish'|'read', session_id, path, ip, state, occurred_at }`.

### `App\Services\SessionDiff` and `App\Services\SessionEvent` (new)

Plain DTOs (readonly classes). `SessionEvent::kind` is derived from `state`:
- `state === 'publish'` → `'publish'`
- `state === 'read'` → `'read'`
- anything else → skip (do not notify for unknown kinds)

### `App\Services\SessionNotifier` (new)

Public method: `dispatch(SessionDiff $diff): void`.

For each event in `started` and `ended`:

1. Determine dedupe key:
   - `notify:publish.started:{session_id}` — always unique per session.
   - `notify:publish.ended:{session_id}` — always unique per session.
   - `notify:read.started:{ip}:{path}` — TTL 300s.
   - `notify:read.ended:{ip}:{path}` — TTL 300s.
2. Call `Cache::add($key, 1, $ttl)`. If it returns `false`, the event is a duplicate — skip it.
3. For `read.started` only, atomically increment `notify:read:flood:{YmdHi}` with TTL 60s. If the value exceeds 20, drop the notification and call `Log::warning(...)` once per minute (gated by a separate cache key).
4. Resolve recipients via `User::role(['super-admin','moderator'])->get()`, cached for 60s under key `notify:recipients:admins`.
5. Build the `Filament\Notifications\Notification`:
   - `title` and `body` per User-facing behaviour above.
   - `color('success')` for started, `color('gray')` for ended.
   - `icon('heroicon-o-signal')`.
   - One action `View sessions` linking to `Sessions::getUrl()`.
   - `sendToDatabase($recipients)`.

The notifier is the only place that knows about Filament's notification class — the refresher returns plain DTOs.

### `App\Console\Commands\RefreshAndNotifySessions` (new)

- Signature: `sessions:refresh-and-notify`.
- Body:
  ```
  try {
      $diff = app(MediamtxSessionRefresher::class)->refresh();
      app(SessionNotifier::class)->dispatch($diff);
  } catch (\Throwable $e) {
      Log::warning('sessions:refresh-and-notify failed', ['exception' => $e]);
  }
  return self::SUCCESS;
  ```
- Registered in `routes/console.php` (Laravel 12 console scheduling):
  ```
  Schedule::command('sessions:refresh-and-notify')
      ->everyTenSeconds()
      ->withoutOverlapping()
      ->runInBackground();
  ```

### Filament panel — enable database notifications

`AdminPanelProvider::panel()` is modified to add `->databaseNotifications()`. This activates the bell widget and the `database_notifications` table (Laravel default migration already shipped with Filament install will be present; if missing, run the Filament-provided migration).

## Data flow

1. Scheduler invokes `sessions:refresh-and-notify` every 10s.
2. The refresher fetches the current MediaMTX session list, computes the diff against the DB, persists the new state, and returns started/ended events.
3. The notifier filters via `Cache::add()`-based dedupe and the per-minute flood cap, then writes one row per `(recipient, event)` into `database_notifications`.
4. Each admin's Filament bell shows the unread count and renders the notifications.

## Configuration

No new env vars are required. The following constants live in code as private const on the respective classes (overridable later if needed):

- `SessionNotifier::READ_DEDUPE_TTL_SECONDS = 300`
- `SessionNotifier::READ_FLOOD_CAP_PER_MINUTE = 20`
- `RefreshAndNotifySessions` interval: `everyTenSeconds()`

## Error handling

| Failure | Behaviour |
|---|---|
| MediaMTX API unreachable | Caught at command level; logged at `warning`; no notifications sent; command exits success so scheduler does not back off. |
| Cache backend unavailable | `Cache::add()` exception caught around the notifier loop; logged; the affected batch is skipped. |
| Database notifications table missing | Filament install would have created it; if absent, surfaced as a deployment error (not handled in code). |
| Overlapping scheduler runs | Prevented by `withoutOverlapping()` lock. |
| Zero recipients | Notifier returns early after recipient lookup; no work. |

## Testing

Feature tests under `tests/Feature/SessionNotifications/` using the existing in-memory SQLite setup. The `RolesSeeder` is invoked in `setUp()` so role lookups behave as in production.

1. `RefresherDiffTest`
   - Given a fake `MediamtxApi` that returns a fixed list of session items and a pre-populated `mediamtx_sessions` table, assert the returned `SessionDiff::$started` and `$ended` contain the right `session_id`s with the correct `kind`.
   - Assert sessions with unknown `state` are not included in either set.

2. `SessionNotifierTest`
   - Publish started event: one `database_notifications` row per recipient with the expected title.
   - Read started events from the same `(ip, path)` within 5 minutes: only the first produces a notification.
   - Read started events: 21st in the same wall-clock minute is dropped; log line emitted at most once per minute.
   - Publish events are never deduped and never affected by the read flood cap.
   - Monitoring-role user receives no notifications; super-admin and moderator do.
   - Ended events fire for each session_id once.

3. `RefreshAndNotifySessionsCommandTest`
   - With a mocked `MediamtxApi`, invoking `Artisan::call('sessions:refresh-and-notify')` produces the expected database notifications and returns success.
   - When the API throws, the command logs a warning and still returns success.

## Open items / migration notes

- If `databaseNotifications()` is being enabled for the first time on this panel, verify the Filament-provided `database_notifications` migration is present in the repo. If absent, add it via `php artisan vendor:publish --tag=filament-notifications-migrations` and check it in. (This is a one-time setup step — the implementation plan should confirm and act on it.)
- No backfill is needed for currently-active sessions: when the command first runs, all existing `mediamtx_sessions` rows still match incoming API ids, so the diff is empty and no startup spam fires.
