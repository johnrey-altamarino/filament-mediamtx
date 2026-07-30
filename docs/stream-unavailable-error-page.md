# Stream-Unavailable Error Page

## What

A single branded screen shown to any viewer who is not eligible to watch a stream, replacing
Laravel's stock 403 page and the players' raw red status text.

Heading: **Stream unavailable** · Subtext: **This video isn't available here.**

## Why

Ineligible viewers previously saw one of two unpolished things: Laravel's default 403 page (on a
page-load rejection), or a small red `Token request denied (403)` status line (when the page had
already loaded and the background token fetch was denied). Since the player is usually embedded in
a third-party site, that is what the embedding site's visitors saw in place of the video.

The copy is deliberately **identical for every rejection cause**. Varying it by cause (blocked IP
vs. disallowed embed origin vs. direct playback off) would let an attacker probe which gate
tripped.

## How

`resources/views/partials/stream-unavailable.blade.php` holds the markup, copy, and all CSS
inline — no Tailwind, no `@vite`, no external assets, so it renders instantly and identically
inside a small cross-origin iframe. It accepts one optional `$hidden` bool.

It is consumed two ways:

**1. Page-load rejection** — `resources/views/errors/stream-unavailable.blade.php` wraps the
partial in a minimal HTML document, returned with a 403 status from:

| Location | Cause |
|---|---|
| `PlayerAccessMiddleware::deny()` | blocked IP (blacklist mode), unlisted IP (whitelist mode), disallowed iframe embed origin |
| `PlayerController::__invoke()` | direct playback disabled and the request is not an embed |
| `MoqPlayerController::__invoke()` | same |

**2. Post-load rejection** — `player.blade.php` and `moq-player.blade.php` each `@include` the
partial with `['hidden' => true]` as a fixed-position overlay, revealed by `showIneligible()` when
`POST /api/read` returns **403** specifically.

- `player.blade.php` branches in `initPlayer()`'s token catch block and sets an `ineligible` flag
  so the `visibilitychange` handler stops re-POSTing to a gate that already denied.
- `moq-player.blade.php` records the status in a module-scoped `deniedStatus` inside
  `fetchToken()`, because the vendored reader passes only `err.message` to `onError`. The
  vendored `resources/js/moq-reader.js` is **not** modified.

Everything else is untouched: non-403 failures still use the existing inline status text, and the
MoQ `showFatal()` box still handles unsupported browsers and missing `MEDIAMTX_MOQ_BASE_URL`.

## Verify

```bash
php artisan test --filter PlayerAccessMiddlewareTest
php artisan test --filter PlayerControllerTest
php artisan test --filter MoqPlayerControllerTest
```

Manually: with direct playback off, `GET /play` returns 403 and renders the branded page; with it
on and your IP on the blocked list, the player loads and then swaps in the same screen.

## Deploy

View-only and controller changes — no migration, no new env var, no asset rebuild (the partial
carries its own CSS and is not part of the Vite bundle). Standard deploy plus:

```bash
php artisan view:clear
php artisan config:clear
```
