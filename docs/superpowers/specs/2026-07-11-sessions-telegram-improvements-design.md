# Design: Sessions & Telegram Improvements (MoQ, all-protocol kick, security alerts)

**Date:** 2026-07-11
**Branch:** `develop` (the single source of truth; `develop-new` is out of scope)
**Status:** Approved design → ready for implementation plan

## Goal

Three related improvements to the existing Live Sessions page and Telegram
notification pipeline (both already wired on `develop`):

1. **MoQ sessions** are visible on the Live Sessions page and trigger Telegram
   notifications, symmetric to WebRTC.
2. **Kick works for every protocol**, not just WebRTC.
3. **Admin/security actions** (kicks, IP blocks, referrer denials, blocked-IP
   hits) generate Telegram alerts from a single integration point.

## Context / current state

- `MediamtxApi` and `MediamtxSessionRefresher` are **WebRTC-only** today
  (`/v3/webrtcsessions/list`, `kickWebRtc`, `findSession` searches only WebRTC).
- The Live Sessions page ([Sessions.php](../../../app/Filament/Pages/Sessions.php))
  lists the `mediamtx_sessions` snapshot and offers Play / Kick / Block-IP. Kick
  calls `kickWebRtc` unconditionally.
- Session start/end Telegram alerts flow: MediaMTX webhook →
  `MediamtxEventController` → `SessionNotifier` → `SendTelegramMessage` job →
  `TelegramNotifier`. The read/unread → alert mapping is already
  protocol-agnostic; `protocolLabel()` just lacks a MoQ case.
- **Every security event already logs to the `security` activity channel:**
  `session.kicked`, `ip.blocked.from_session` (Sessions page);
  `token.read.denied.blocked_ip`, `token.read.denied.bad_referer`
  (ReadTokenController); plus IP/referrer/token CRUD in the resources.

### Confirmed MediaMTX v1.19.1 API surface

| Protocol | List | Kick |
|---|---|---|
| WebRTC | `/v3/webrtcsessions/list` | `/v3/webrtcsessions/kick/{id}` |
| MoQ | `/v3/moqsessions/list` | `/v3/moqsessions/kick/{id}` |
| RTMP | `/v3/rtmpconns/list` | `/v3/rtmpconns/kick/{id}` |
| RTSP | `/v3/rtspsessions/list` | `/v3/rtspsessions/kick/{id}` |
| SRT | `/v3/srtconns/list` | `/v3/srtconns/kick/{id}` |
| HLS | `/v3/hlsmuxers/list` | *(no kick — read-only muxer)* |

Reader/source `type` strings from hooks: `webRTCSession`, `moqSession`,
`rtmpConn`, `rtspSession`, `srtConn`, `hlsMuxer`. All list items share the common
fields `id`, `created`, `remoteAddr`, `state`, `path`; `userAgent` is present for
WebRTC only (default `null` for the rest). Exact per-protocol field names are
verified during implementation via HTTP-fake tests.

## Design decisions

- **D1 — Session listing: all protocols.** The refresher pulls all five list
  endpoints so the page is a true "all live sessions" view and kick has
  something to act on for every protocol.
- **D2 — Security alerts: activity-log-driven, curated allowlist.** A single
  listener on `Activity` creation forwards only an allowlisted set of
  `security`-channel events to Telegram, sharing formatting/flood-cap helpers
  with `SessionNotifier`.

## Workstream 1 — MoQ sessions (API + page + alerts)

**`MediamtxApi`**
- `listMoqSessions(): array` → `GET /v3/moqsessions/list`.
- Extend session enrichment for hooks so MoQ read/unread events resolve IP (and
  UA when present). Preferred shape: `findSession(string $id, string $type = '')`
  that queries the list endpoint matching `$type` (falling back to WebRTC then
  MoQ), keeping enrichment to a single API call per hook. `MediamtxEventController`
  passes the hook's `type` through.

**`MediamtxSessionRefresher`**
- `syncSnapshot()` merges rows from all five list endpoints, each tagged with its
  `type` (`webrtc`/`moq`/`rtmp`/`rtsp`/`srt`). Reuse `MediamtxApi::clientIp()`
  (already falls back to `remoteAddr` when `remoteCandidate` is absent).
- Each list call is best-effort: a failure/unreachable endpoint is skipped so one
  protocol being down does not blank the whole page. Stale-row cleanup
  (`stale_seconds`) and the `updateOrCreate(['type','session_id'])` key are
  unchanged — new types slot in automatically.

**Alerts**
- Add `'moqSession' => 'MoQ'` to `SessionNotifier::protocolLabel()`. MoQ
  read/unread hooks then flow through the existing pipeline with correct labels
  and (via the enrichment above) IP.

## Workstream 2 — Kick all protocols

**`MediamtxApi`**
- Add `kickMoq/kickRtmp/kickRtsp/kickSrt` and a `kick(string $type, string $id)`
  dispatcher mapping `type → endpoint` (unknown/`hls` → no-op or thrown
  `InvalidArgumentException`, caught by the action).

**Sessions page**
- Kick action calls `app(MediamtxApi::class)->kick($record->type, $record->session_id)`.
- Kick action hidden when `$record->type === 'hls'` (no kick endpoint).
- Security logging (`activity('security')->log('session.kicked')`) unchanged —
  which, via Workstream 3, now also produces a Telegram alert.

## Workstream 3 — Telegram admin/security alerts

**Single listener on Activity creation** (`SecurityAlertListener`, registered as
an observer/`created` hook on the configured activity model):

1. Return unless `log_name === 'security'`.
2. Return unless `description` is in the configured allowlist.
3. Format a message (emoji per event type, causer username, relevant properties —
   ip / path / session / reason / referer, `Asia/Manila` timestamp), reusing the
   shared HTML-escaping helper.
4. Apply a per-minute flood cap (reuse the `SessionNotifier` read-flood pattern)
   — important because `token.read.denied.*` can spike under attack.
5. `SendTelegramMessage::dispatch($text)`.

**Default curated allowlist** (`config('services.telegram.security_events')`):
`session.kicked`, `ip.blocked.from_session`, `ip.blocked`, `ip.unblocked`,
`token.read.denied.blocked_ip`, `token.read.denied.bad_referer`. Routine CRUD
(referrer/token add-rotate-delete) is excluded by default.

**Shared helpers (refactor):** extract HTML escaping + the per-minute flood-cap
into a small support unit (e.g. `App\Support\TelegramAlert`) used by both
`SessionNotifier` and `SecurityAlertListener`. `SessionNotifier`'s external
behavior is unchanged.

**Config / env:** add `security_events` (allowlist) and a
`security_alerts_enabled` flag (default `true`) to the `telegram` block in
`config/services.php`; mirror in `.env.example`.

## Component boundaries

| Unit | Responsibility | Depends on |
|---|---|---|
| `MediamtxApi` | Typed access to all MediaMTX list/kick endpoints; IP/UA enrichment | HTTP client |
| `MediamtxSessionRefresher` | Merge all-protocol snapshots into `mediamtx_sessions` | `MediamtxApi` |
| `Sessions` page | Render snapshot; Play/Kick/Block routed by type | `MediamtxApi`, models |
| `SessionNotifier` | Format session start/end alerts | shared `TelegramAlert`, `SendTelegramMessage` |
| `SecurityAlertListener` | Forward allowlisted `security` activity to Telegram | shared `TelegramAlert`, `SendTelegramMessage` |
| `TelegramAlert` (support) | HTML escaping + flood cap | Cache |

## Error handling

- Per-endpoint list failures are swallowed in the refresher (log-and-skip).
- `kick()` on an unknown/HLS type surfaces a user-facing danger notification
  rather than a 500.
- Telegram send failures already log a warning in `TelegramNotifier`; the queued
  job retries (`tries=3`, `backoff=10`).
- Flood cap protects against alert storms from denial events.

## Testing (TDD, SQLite in-memory, `Http::fake`)

- `MediamtxApi`: `listMoqSessions` parses items; `kick($type,$id)` hits the
  correct endpoint for each type; `hls` type is rejected.
- `MediamtxSessionRefresher`: all five endpoints faked → rows created with correct
  `type`; one failing endpoint does not abort the others; stale cleanup intact.
- `Sessions` page: kick action calls the type-correct endpoint; kick hidden for
  HLS; block-IP/play unchanged.
- Webhook: `moqSession` read event → `MoQ` label and IP enrichment via
  `moqsessions/list`.
- `SecurityAlertListener`: allowlisted `security` activity dispatches
  `SendTelegramMessage` (`Bus`/`Queue` fake); non-allowlisted and non-security
  entries dispatch nothing; flood cap suppresses beyond the cap.

## Out of scope

- `develop-new` and any branch consolidation.
- New alert transports (email/Slack) or per-user routing.
- Changes to the MoQ player itself, WHIP/WHEP ingest, or `/api/read` auth.

## Docs (per CLAUDE.md)

- New guide `docs/sessions-telegram-improvements.md` (Symptom/Goal → Design →
  Verify → Deploy).
- Update `README.md`: Live Sessions now covers all protocols; Telegram security
  alerts + new env vars; keep the table-of-contents in sync.
