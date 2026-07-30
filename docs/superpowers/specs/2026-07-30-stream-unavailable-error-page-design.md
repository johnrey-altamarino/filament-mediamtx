# Stream-Unavailable Error Page — Design Spec
**Date:** 2026-07-30
**Branch:** production

---

## Overview

Ineligible viewers — a blocked IP, an iframe embed from a site not on the referrer allowlist, or
direct playback while `allow_direct_playback` is off — are currently rejected in one of two
unpolished ways:

1. **Page-load rejection** (`PlayerAccessMiddleware`, `PlayerController`, `MoqPlayerController`):
   a plain `abort(403)` that falls through to Laravel's stock default 403 error page (no custom
   `resources/views/errors/*.blade.php` exists today).
2. **In-page rejection** (`player.blade.php`, `moq-player.blade.php`): the player page loads fine,
   but its background `POST /api/read` token fetch is denied with a 403 (e.g. the IP got blocked
   after the page already rendered). Today this just swaps in small red status text
   ("Couldn't load stream: Token request denied (403)") or reuses the generic `showFatal()` box.

This spec adds one shared, on-brand error screen (matching the player's existing full-bleed
black-background aesthetic, since this page is frequently rendered *inside a small third-party
iframe* in place of the video) and wires it into all three rejection paths.

**Out of scope:** any other 4xx/5xx path (missing `?stream=` param, 500s, browser-unsupported
message in the MoQ player, MediaMTX misconfiguration, WHEP retry-exhaustion "Stream unavailable
right now") — these are different failure classes than "not eligible" and are left exactly as
they are today.

---

## Messaging

One static, reason-agnostic message is used regardless of *why* the viewer was rejected (blocked
IP vs. disallowed embed origin vs. direct-playback disabled). Revealing which specific gate
tripped would help an attacker probe the rules, so the copy never varies by cause:

- Heading: **"Stream unavailable"**
- Subtext: **"This video isn't available here."**

No dynamic parameters are passed into the view/partial — the copy is fixed.

---

## Components

### 1. Shared partial — `resources/views/partials/stream-unavailable.blade.php`

Self-contained markup + scoped `<style>` (no Tailwind/Vite dependency, so it renders identically
and instantly inside a tiny third-party iframe):

- A muted "no-playback" icon: the same play-triangle path already used in `player.blade.php`'s
  tap-overlay (`M8 5v14l11-7z`), inside a circle with a diagonal slash — reads as "playback
  blocked" without being alarming (no red, no lock).
- Heading + subtext as above.
- A subtle opacity "breathing" animation reusing the existing `@keyframes pulse` pattern from
  `player.blade.php`'s spinner, wrapped in `@media (prefers-reduced-motion: reduce)` to disable it.
- Layout: `position: fixed; inset: 0;` flex-centered column, solid black background, so it can be
  dropped in either as a full document body or as an overlay above existing player content.

No Blade variables/props — purely static include.

### 2. Standalone error page — `resources/views/errors/stream-unavailable.blade.php`

A full HTML5 document mirroring `player.blade.php`'s `<head>` conventions (favicon link, charset,
viewport meta, `<title>Stream Unavailable</title>`), with black `html, body` background and
`@include('partials.stream-unavailable')` in the body.

Wired in by replacing `abort(403)` with
`return response()->view('errors.stream-unavailable', [], 403);` at each call site:

- `app/Http/Middleware/PlayerAccessMiddleware.php` — 3 call sites (whitelist-IP-fail line ~34,
  blacklist-IP-fail line ~39, embed-referrer-fail line ~63)
- `app/Http/Controllers/PlayerController.php` — direct-playback-disabled abort (line ~34)
- `app/Http/Controllers/MoqPlayerController.php` — same (line ~33)

HTTP status stays `403` in all cases — only the response body changes from Laravel's default
error page to the branded one. No new abstraction/helper is introduced for these 5 call sites;
each is a one-line `return response()->view(...)` matching the existing local style.

### 3. In-page upgrade — `player.blade.php` and `moq-player.blade.php`

Both pages get the same partial `@include`d as a hidden overlay div, positioned above the
existing loader/video (`z-index` higher than `#loader`'s `2`), toggled visible via a small JS
helper (e.g. `showIneligibleScreen()`).

**`player.blade.php`** (`resources/views/player.blade.php`): in `initPlayer()`'s token-fetch catch
block (~line 475), branch specifically on `err.message === "Token request denied (403)"` to show
the overlay instead of the existing `setStatus(...)` red-text call. Any other error
(500/422-after-retries/network) keeps today's inline status text — unchanged.

**`moq-player.blade.php`** (`resources/views/moq-player.blade.php`): in the reader's `onError`
terminal branch (~line 207), when the underlying HTTP status was specifically `403`, show the
overlay instead of routing through the existing `showFatal()` box. `showFatal()` itself is
untouched and keeps handling its other jobs (unsupported browser, missing `MOQ_BASE_URL` config).

To detect the specific status inside `moq-player.blade.php`'s `fetchToken()`, the thrown error
needs to carry the raw status (it currently only sets a boolean `err.terminal`); add
`err.status = res.status` alongside the existing `err.terminal` assignment so the `onError`
handler can check `status === 403` without changing the existing terminal/retry behavior for
other 4xx codes.

---

## Testing

- Existing Feature tests asserting `assertForbidden()` / 403 status on
  `PlayerAccessMiddleware`/`PlayerController`/`MoqPlayerController` continue to pass unchanged
  (status code doesn't change). Add an assertion that the response body contains the new view's
  marker text (e.g. `"Stream unavailable"`) to lock in the branded page.
- No JS test harness exists in this repo. The in-page overlay (`player.blade.php` /
  `moq-player.blade.php`) is verified manually: trigger a blocked-IP or disallowed-referrer
  `/api/read` response and confirm the overlay renders in place of the red status text, in both a
  normal tab and embedded in a small iframe (to confirm the layout holds up at iframe scale).

---

## Documentation

Per repo convention, write a `docs/` guide for this change after implementation (Symptom → Root
cause → Fix → Verify → Deploy shape isn't quite right for a new feature — use a short
what/why/how note instead), and update `README.md` if it documents the player error-handling
behavior.
