# Telegram alerts via MediaMTX hooks + query-lean sessions — Design

Date: 2026-06-04
Branch: developv2

## Problem

The app queries the MediaMTX API and writes the `mediamtx_sessions` table continuously,
even when nobody is watching, driven by three independent paths:

1. A scheduled command `sessions:refresh-and-notify` runs **every 10s, 24/7**, each tick
   spawning a fresh PHP process that boots the whole framework
   (`routes/console.php`, `->everyTenSeconds()->runInBackground()`). This is the main
   CPU/RAM churn on the small monolith VM — it runs even with zero live streams and no
   admin logged in.
2. The `SessionsOverview` dashboard widget calls `MediamtxSessionRefresher::refresh()` on
   **every 60s render, per logged-in admin** (`app/Filament/Widgets/SessionsOverview.php`).
3. The Filament bell polls the DB **every 15s, per admin browser**
   (`AdminPanelProvider::databaseNotificationsPolling('15s')`).

The `mediamtx_sessions` table itself stays small (stale rows pruned every cycle), so RAM
growth is not from table size — it is from the continuous process/query load.

`bytes_received` / `bytes_sent` are tracked and stored but are not needed in the UI.

## Goals

- Stop all 24/7 background querying of MediaMTX.
- Query MediaMTX **only** when (a) a session event fires, or (b) an admin is actively
  viewing the Sessions page or dashboard.
- Deliver session start/stop alerts to **Telegram** (reachable on phone, works behind ISP
  NAT via outbound HTTPS, no inbound port / WebSocket / Pusher / Reverb required).
- Remove `bytes_received` / `bytes_sent` entirely.

## Non-goals

- No WebSocket / Reverb / Pusher.
- No in-app database-notification bell (Telegram replaces it).
- No general refactor beyond what serves the above.

## Core principle

**Two independent paths, neither of which polls in the background:**

- **Alerting (event-driven):** MediaMTX hooks → localhost Laravel webhook → queued Telegram
  send. Fires only when a session actually starts/stops.
- **Viewing (on-demand):** the Sessions page and dashboard widget sync a snapshot from the
  MediaMTX API only while open. Closed = zero queries.

## Data flow

```
Session starts/ends
   │
   ▼
MediaMTX  (runOnReady / runOnNotReady / runOnRead / runOnUnread in mediamtx.yml)
   │  curl POST  (127.0.0.1, X-Webhook-Secret)
   ▼
Laravel  POST /api/mediamtx/event  ──►  validate secret  ──►  return 204 fast
   │
   ▼  (queued job — never blocks the MediaMTX hook command)
TelegramNotifier  ──►  HTTPS POST api.telegram.org/bot<token>/sendMessage  ──►  phone
   (reuses existing dedupe + read-flood-cap so bursts don't spam Telegram)

Sessions page / dashboard open
   │  Livewire poll (only while open)
   ▼
MediamtxSessionRefresher::syncSnapshot()  ──►  upsert live rows + delete stale
   │
   ▼
mediamtx_sessions  ──►  rendered table / counts
```

## Components

### New

- **`config/services.php`**
  - `telegram` => `bot_token` (env `TELEGRAM_BOT_TOKEN`), `chat_id` (env
    `TELEGRAM_CHAT_ID`), `enabled` (env `TELEGRAM_ALERTS_ENABLED`, default false).
  - `mediamtx.webhook_secret` (env `MEDIAMTX_WEBHOOK_SECRET`).
- **`TelegramNotifier` service** — sends one message via
  `Http::timeout(5)->post('https://api.telegram.org/bot{token}/sendMessage', [...])`.
  Invoked from a queued job so a slow/down Telegram never blocks the request. No-op when
  `telegram.enabled` is false or token/chat missing.
- **`POST /api/mediamtx/event`** route in `routes/api.php` → `MediamtxEventController`.
- **`VerifyMediamtxWebhook` middleware** — constant-time compare of `X-Webhook-Secret`
  against `services.mediamtx.webhook_secret`; optionally restrict to loopback peer.
  Mirrors the style of the existing `AllowMediamtxOnly` middleware. 403 on mismatch.
- **`MediamtxEventController`** — maps the hook payload (`event`, `path`, reader/source id,
  optional IP) to a `SessionEvent`, hands it to the repurposed `SessionNotifier`, returns
  204. If the read hook lacks the client IP, do **one** MediaMTX API lookup to resolve it
  (event-driven, not polling) — exact env vars to be confirmed against the deployed
  MediaMTX version during implementation.
- **`docs/` mediamtx.yml hook snippet** — documented `runOnReady` / `runOnNotReady` /
  `runOnRead` / `runOnUnread` commands the operator adds on the server + restart.

### Repurposed

- **`SessionNotifier`** — keep `passesDedupe()` and `passesReadFloodCap()` verbatim; change
  delivery from `->sendToDatabase($recipients)` to `TelegramNotifier`. Now driven by single
  events from the webhook rather than a `SessionDiff`.

### Simplified

- **`MediamtxSessionRefresher`** — reduce to `syncSnapshot()`: upsert live rows + delete
  stale rows. Drop the started/ended diff loops and the pre-upsert `SELECT`. The
  `SessionDiff` / `SessionEvent` DTOs are no longer produced by polling (event data now
  comes straight from the hook); `SessionEvent` is retained only if reused by the webhook
  path, otherwise removed along with `SessionDiff`.
- **`SessionsOverview` widget** — count from the DB; trigger `syncSnapshot()` only on its
  own 60s poll (runs only while the dashboard is open).
- **`Sessions` page** — `refreshSessions()` calls `syncSnapshot()`. Relax the blade
  `wire:poll.5s` to `wire:poll.10s`.

### Removed

- The `Schedule::command(RefreshAndNotifySessions::class)->everyTenSeconds()...` entry in
  `routes/console.php` and the `RefreshAndNotifySessions` command class.
- `->databaseNotifications()` and `->databaseNotificationsPolling('15s')` in
  `AdminPanelProvider` (no bell → no per-admin 15s DB poll).
- `bytes_received` / `bytes_sent` — Sessions table columns, model `$fillable` / `$casts`,
  refresher row map, **plus a migration dropping both columns** from `mediamtx_sessions`.

## Error handling

- Webhook validates the secret first; returns 403 on mismatch, 204 on success — fast, before
  any Telegram work.
- Telegram send is a queued job (DB queue, already configured) with a short HTTP timeout and
  retries. A down/slow Telegram never blocks the MediaMTX hook command.
- `TelegramNotifier` is a no-op (logged once) when disabled or misconfigured, so the webhook
  path stays green in dev/test where Telegram isn't set up.

## Testing

- `MediamtxEventController`: 403 on bad/missing secret; 204 on valid payload; dispatches the
  Telegram job (faked queue); dedupe + flood-cap suppress repeats.
- `TelegramNotifier`: builds the correct request (HTTP faked); no-op when disabled.
- `MediamtxSessionRefresher::syncSnapshot()`: upserts live rows, deletes stale rows, no
  longer references bytes.
- `Sessions` page / `SessionsOverview`: render from DB; sync invoked on poll.
- Migration: `bytes_*` columns dropped; rollback restores them.

## Operator steps (outside code)

1. Create a Telegram bot via @BotFather, capture bot token + chat id.
2. Set `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`, `TELEGRAM_ALERTS_ENABLED=true`,
   `MEDIAMTX_WEBHOOK_SECRET` in `.env`.
3. Add the documented hook commands to `mediamtx.yml`; restart MediaMTX.
4. Ensure a queue worker is running (already required by the app).
