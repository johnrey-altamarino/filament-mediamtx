# Multistream Monitor — Design

**Date:** 2026-06-30
**Status:** Approved (design), pending implementation plan

## Goal

Add an admin page that shows a live grid of the **currently-active publish streams** on
the MediaMTX server. Each tile plays the stream, shows its path name, and offers an
"Open" (full single player) and "Copy" (player URL) action. The grid shows a maximum of
**6 tiles per page**; additional active streams paginate to subsequent pages.

## Context

The app already has:
- A single-stream WHEP player at `GET /play?stream=<path>` (`PlayerController` + `player.blade.php`)
  that fetches a short-lived read token from `POST /api/read` and plays via WebRTC.
- `MediamtxApi` — HTTP client for the MediaMTX v3 REST API (currently list/kick WebRTC
  sessions only).
- A `clipboard-copy` Livewire browser event already wired into the panel body for copy actions.

### Why no backend security changes are needed

The tiles embed `/play` via iframe **from the panel's own origin**. The existing code already
permits this end-to-end:
- `PlayerAccessMiddleware` sets `Content-Security-Policy: frame-ancestors 'self' …`, so
  same-origin framing is allowed; and its embed gate allows any source starting with `APP_URL`.
- `ReadTokenController::refererAllowed()` already returns `true` when the request's referer
  origin equals the app origin (the inner `/api/read` fetch from the iframe is same-origin).

Therefore the "auto-allow same-origin admin" requirement is satisfied by existing code. No
changes to `ReadTokenController` or `PlayerAccessMiddleware`.

> Operator note (not a code change): the player IP gate in `PlayerAccessMiddleware` still
> applies to the admin's browser IP. In `whitelist` IP mode the admin's IP must be allowed,
> or the iframes 403. This is existing, operator-configured behavior.

## Components

### 1. `MediamtxApi::listPaths(): array`
New method. `GET /v3/paths/list`, `->throw()->json()`. Returns the decoded response
(the caller reads `items`). Consistent with the existing `listWebRtcSessions()` style.

### 2. `App\Services\ActivePublishPaths`
Small, testable service that wraps `MediamtxApi` and filters to genuinely-live publishers.

- `get(): array` — calls `listPaths()`, reads `items`, keeps only items where
  `ready === true` **and** `source !== null`. Maps each to a clean shape:
  ```php
  [
      'name'        => string,   // the MediaMTX path, e.g. "live/stream1"
      'source_type' => string,   // data_get($item, 'source.type', '')
      'tracks'      => array,     // data_get($item, 'tracks', [])
      'readers'     => int,       // count(data_get($item, 'readers', []))
  ]
  ```
- On any `Throwable` from the API call, return `[]` (page shows empty-state, never crashes).
- Sort by `name` for stable ordering across polls (so tiles don't reshuffle).

### 3. `App\Filament\Pages\MultistreamMonitor`
Filament `Page`.
- `navigationIcon = heroicon-o-squares-2x2`, `navigationLabel = 'Multistream Monitor'`.
- `public static function canAccess(): bool` → `true` for `super-admin`, `moderator`,
  `monitoring` (all three roles). Match the existing inline-gate style used elsewhere.
- `public int $page = 1;` — current grid page (1-indexed).
- `protected const PER_PAGE = 6;`
- `refresh(): void` — (re)loads the active list from `ActivePublishPaths` into a public
  property. Called on mount and by `wire:poll`.
- Computed helpers for the view:
  - current-page slice of 6 tiles,
  - `totalPages` = `max(1, ceil(count / PER_PAGE))`,
  - clamp `$page` into `[1, totalPages]` (handles streams ending and shrinking the list),
  - `prevPage()` / `nextPage()` methods.

### 4. View: `resources/views/filament/pages/multistream-monitor.blade.php`
- Wrapper has `wire:poll.12s="refresh"`.
- Responsive grid: 3 columns × 2 rows on desktop, collapsing to 2 / 1 columns on smaller
  screens (Tailwind responsive classes).
- Per tile, keyed for morphdom stability:
  ```blade
  <div wire:key="tile-{{ $tile['name'] }}">
      <header>{{ $tile['name'] }}</header>
      <iframe src="/play?stream={{ urlencode($tile['name']) }}&muted=true&controls=false&autoplay=true"
              allow="autoplay"></iframe>
      <button>Open</button>   {{-- opens /play?stream=<path> in a new tab --}}
      <button>Copy</button>   {{-- copies the player URL via clipboard-copy event --}}
  </div>
  ```
  The `wire:key` keyed by path ensures `wire:poll` re-renders reuse the existing iframe DOM
  node, so playback is **not** interrupted when the list refreshes.
- Empty-state ("No active publishers") when the list is empty.
- Pagination controls (prev / next + "Page X of Y") below the grid; hidden/disabled at bounds.

## Data Flow

```
wire:poll.12s ──> MultistreamMonitor::refresh()
                     └─> ActivePublishPaths::get()
                            └─> MediamtxApi::listPaths()  (GET /v3/paths/list)
                            └─> filter ready && source, map, sort by name
                  ──> slice current 6  ──> render tiles
                            └─> each iframe runs existing token + WHEP logic independently
```

## Error Handling

- MediaMTX API unreachable / errors → `ActivePublishPaths::get()` returns `[]`; page renders
  the empty-state. No exception surfaces to the user.
- A path that ends between polls drops off the next render; its keyed tile (and iframe) is
  removed from the DOM.
- `$page` is clamped after each refresh so a shrinking list can't strand the user on an empty
  page.

## Testing

- **`MediamtxApi::listPaths()`** — `Http::fake` returns a sample `/v3/paths/list` body; assert
  the request hits the right URL and the items are returned.
- **`ActivePublishPaths`** — given mixed items (ready+source, ready+no-source, not-ready),
  returns only the live publisher(s) in the mapped shape; returns `[]` when the API throws.
- **`MultistreamMonitor`** (Livewire/Filament page test):
  - slices to 6 per page and paginates correctly (e.g. 7 active → 2 pages),
  - `$page` clamps when the list shrinks,
  - `canAccess()` is true for each of the three roles,
  - renders one tile (with the path name) per active publisher.

## Out of Scope (YAGNI)

- Configurable page size (fixed 6).
- Per-tile kick / block (lives on the existing Sessions page).
- Audio mixing, recording, layout customization, drag-reorder.
- Any new public/unauthenticated endpoint.
