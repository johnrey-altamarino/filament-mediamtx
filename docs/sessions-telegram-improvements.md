# Live Sessions (all protocols) + Telegram security alerts

## Goal

Three related improvements to the existing Live Sessions page and Telegram notification pipeline:

1. **MoQ sessions** are visible on the Live Sessions page and trigger Telegram notifications,
   symmetric to WebRTC.
2. **Kick works for every protocol** the page lists, not just WebRTC.
3. **Admin/security actions** (kicks, IP blocks/unblocks, referrer denials, blocked-IP hits)
   generate Telegram alerts from a single integration point, gated by a curated, configurable
   allowlist.

## Design

Full design doc:
[`docs/superpowers/specs/2026-07-11-sessions-telegram-improvements-design.md`](superpowers/specs/2026-07-11-sessions-telegram-improvements-design.md).
Implementation plan:
[`docs/superpowers/plans/2026-07-11-sessions-telegram-improvements.md`](superpowers/plans/2026-07-11-sessions-telegram-improvements.md).

### MediaMTX v1.19.1 endpoint table

| Protocol | List | Kick |
|---|---|---|
| WebRTC | `/v3/webrtcsessions/list` | `/v3/webrtcsessions/kick/{id}` |
| MoQ | `/v3/moqsessions/list` | `/v3/moqsessions/kick/{id}` |
| RTMP | `/v3/rtmpconns/list` | `/v3/rtmpconns/kick/{id}` |
| RTSP | `/v3/rtspsessions/list` | `/v3/rtspsessions/kick/{id}` |
| SRT | `/v3/srtconns/list` | `/v3/srtconns/kick/{id}` |
| HLS | `/v3/hlsmuxers/list` | *(no kick — read-only muxer; not synced into the Sessions table)* |

`MediamtxSessionRefresher::syncSnapshot()` (`app/Services/MediamtxSessionRefresher.php`) now pulls
all five list endpoints above (WebRTC/MoQ/RTMP/RTSP/SRT) into `mediamtx_sessions` on every page load.
Each protocol is fetched independently and best-effort — one endpoint being unreachable is logged and
skipped, not allowed to blank the rest of the table. Stale rows (not seen within
`MEDIAMTX_SESSION_STALE_SECONDS`, default 20s) are still pruned the same way.

`MediamtxApi::kick(string $type, string $id)` (`app/Services/MediamtxApi.php`) dispatches by the DB
`type` column to the matching endpoint above; an unknown type or `hls` throws
`InvalidArgumentException`. The Live Sessions page (`app/Filament/Pages/Sessions.php`) calls this
dispatcher from the Kick row action and hides the action entirely when `type === 'hls'`.

### DB `type` values

`mediamtx_sessions.type` stores one of: `webrtc`, `moq`, `rtmp`, `rtsp`, `srt` (HLS is never written
to this table — MediaMTX exposes no HLS "session" concept beyond a muxer list). These are also the
values the Kick dispatcher above switches on.

MediaMTX's own hook-side reader/source `type` strings (`$MTX_READER_TYPE` / `$MTX_SOURCE_TYPE` in
`mediamtx.yml`, consumed by `MediamtxEventController` and `SessionNotifier`) are different literal
strings — `webRTCSession`, `moqSession`, `rtmpConn`, `rtspSession`, `srtConn`, `hlsMuxer` — do not
confuse the two when reading webhook payloads vs. Sessions-table rows.

### Telegram security alerts

New listener `App\Listeners\SecurityAlertListener` (`app/Listeners/SecurityAlertListener.php`),
registered on `Spatie\Activitylog\Models\Activity::created()` in `AppServiceProvider::boot()`:

1. No-op unless both `TELEGRAM_ALERTS_ENABLED` and `TELEGRAM_SECURITY_ALERTS_ENABLED` are true.
2. No-op unless the activity's `log_name` is `security`.
3. No-op unless the activity's `description` is in the configured allowlist
   (`config('services.telegram.security_events')`).
4. Applies a per-minute flood cap (30/min, shared bucket `"security"`) via
   `App\Support\TelegramAlert::floodCap()` — important because `token.read.denied.*` can spike under
   attack/scanning.
5. Formats an HTML `parse_mode` message (emoji + title per event, IP/path/referer/reason properties,
   causer username, `Asia/Manila` timestamp) and dispatches the existing `SendTelegramMessage` job.

`App\Support\TelegramAlert` is a small shared unit extracted so `SessionNotifier` (session start/stop
alerts) and `SecurityAlertListener` (security alerts) share the same HTML-escaping (`esc()`) and
flood-cap (`floodCap()`) logic — no behavior change to the existing session-alert pipeline.

**Default curated allowlist** (`config/services.php`, mirrored in `.env.example`):

```
session.kicked, ip.blocked.from_session, ip.blocked, ip.blocked.from_security_events,
ip.blocked.from_top_ips, ip.unblocked, token.read.denied.blocked_ip, token.read.denied.bad_referer
```

Routine CRUD (referrer/publish-token add/rotate/delete, etc.) is **excluded by default** to avoid
noise — only session-kick, IP-block/unblock, and denied-read events alert by default.

**To extend the allowlist**, set `TELEGRAM_SECURITY_EVENTS` in `.env` to a comma-separated list of
any `security`-channel activity `description` values (see the `security` log calls across
`app/Filament/Pages/Sessions.php`, `app/Http/Controllers/Api/ReadTokenController.php`, and the
`BlockedIpResource` / `AllowedReferrerResource` actions for the full set of possible descriptions),
then restart the queue worker (see Deploy below) so the new config is picked up.

## No new DB migrations

This feature is entirely service/listener/config-level. Nothing was added to `database/migrations/` —
`php artisan migrate` is a no-op deploy step here.

## Verify

```bash
./vendor/bin/pint
php artisan test
```

All feature-relevant test files must be green:
`MediamtxApiTest`, `MediamtxEventControllerTest`, `SessionNotifications/SyncSnapshotTest`,
`SessionsPageKickTest`, `TelegramAlertTest` (Unit), `SessionNotifications/SessionNotifierTest`,
`SecurityAlertListenerTest`.

### Manual verification (staging/prod, optional but recommended)

With MediaMTX reachable:

1. Open Live Sessions, start a MoQ playback via `/moq?stream=<path>` → a row appears with type
   `moq`. Click Kick → the session drops.
2. Trigger a blocked-IP read (`POST /api/read` from a blocked IP) → a "Read denied — blocked IP"
   Telegram message arrives (subject to the 30/min flood cap).

## Deploy

```bash
cd /var/www/mediamtx-admin
php artisan down

git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# No new migrations for this feature — `migrate` is a no-op here, but still safe to run:
php artisan migrate --force
```

Set the two new env vars in `/var/www/mediamtx-admin/.env` (or leave the defaults — both already
default to the values below if unset):

```ini
TELEGRAM_SECURITY_ALERTS_ENABLED=true
TELEGRAM_SECURITY_EVENTS=session.kicked,ip.blocked.from_session,ip.blocked,ip.blocked.from_security_events,ip.blocked.from_top_ips,ip.unblocked,token.read.denied.blocked_ip,token.read.denied.bad_referer
```

Then:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:optimize

sudo chown -R www-data:www-data storage bootstrap/cache
sudo systemctl restart mediamtx-admin-queue   # long-running `queue:work` holds old code/config in
                                               # memory until restarted — required so SendTelegramMessage
                                               # (dispatched by SecurityAlertListener) runs with the
                                               # deployed code and picks up TELEGRAM_ALERTS_ENABLED /
                                               # TELEGRAM_SECURITY_ALERTS_ENABLED changes

php artisan up
```

This is the same "restart the queue worker after deploy" rule already called out in the main
[Updating an existing deployment](../README.md#updating-an-existing-deployment) steps — nothing
Telegram-specific about the mechanism, just a reminder it applies here too since this feature adds a
new source of queued jobs (`SecurityAlertListener` → `SendTelegramMessage`).
