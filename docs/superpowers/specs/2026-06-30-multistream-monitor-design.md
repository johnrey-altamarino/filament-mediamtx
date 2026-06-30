# Multistream Monitor — Design

**Date:** 2026-06-30
**Status:** Approved (design), pending implementation plan

## Goal

Add a **standalone, full-screen monitoring page** that shows a live grid of the
**currently-active publish streams** on the MediaMTX server. It opens in a **new browser tab**
from the admin nav and is guarded **only by authentication** (a logged-in panel user). Each
tile plays the stream and shows its path name, with an "Open" (full single-stream player) and
"Copy" (player URL) action. The grid shows a maximum of **6 tiles per page**; additional active
streams paginate to subsequent pages.

## Context

The app already has:
- A single-stream WHEP player view (`resources/views/player.blade.php` + `resources/js/reader.js`)
  that fetches a short-lived read token from `POST /api/read` and plays via WebRTC.
- A public player route `GET /play` (`PlayerController`, `player.access` middleware) carrying an
  IP gate, an embed/referrer gate, and an `allow_direct_playback` toggle.
- `MediamtxApi` — HTTP client for the MediaMTX v3 REST API (currently list/kick WebRTC sessions only).

### Access model: only guard is the logged-in user

The monitor and its playback must **not** depend on the public player's gates (direct-playback
toggle, player IP gate, referrer allowlist). The only requirement is being an authenticated panel
user. This is achieved by serving the monitor and an **internal player** behind the panel's auth
middleware, separate from the public `/play` route.

Read tokens still issue without any gate change: the internal player is served from the app's own
origin, so its `POST /api/read` fetch is same-origin and `ReadTokenController::refererAllowed()`
already returns `true` when the referer origin equals the app origin
([ReadTokenController.php:87-98](../../../app/Http/Controllers/Api/ReadTokenController.php#L87-L98)).
**No changes to `ReadTokenController` or `PlayerAccessMiddleware`.**

## Components

### 1. `MediamtxApi::listPaths(): array`
New method. `GET /v3/paths/list`, `->throw()->json()`. Returns the decoded response (caller reads
`items`). Mirrors the existing `listWebRtcSessions()` style.

### 2. `App\Services\ActivePublishPaths`
Small, testable service wrapping `MediamtxApi`, filtering to genuinely-live publishers.

- `get(): array` — calls `listPaths()`, reads `items`, keeps only items where `ready === true`
  **and** `source !== null`. Maps each to a clean shape:
  ```php
  [
      'name'        => string,   // the MediaMTX path, e.g. "live/stream1"
      'source_type' => string,   // data_get($item, 'source.type', '')
      'tracks'      => array,     // data_get($item, 'tracks', [])
      'readers'     => int,       // count(data_get($item, 'readers', []))
  ]
  ```
- On any `Throwable` from the API call, return `[]` (page shows empty-state, never crashes).
- Sort by `name` for stable ordering across polls (tiles don't reshuffle).

### 3. Routes (auth-guarded, outside the Filament panel chrome)
Registered in `routes/web.php` under the panel's auth middleware (Filament `Authenticate`, so an
unauthenticated user is redirected to the panel login):

- `GET /monitor` → name `monitor` → full-page Livewire component `App\Livewire\MultistreamMonitor`,
  rendered in a minimal full-screen layout.
- `GET /monitor/play` → name `monitor.play` → `MonitorPlayerController` (internal single-stream player).

### 4. `App\Livewire\MultistreamMonitor` (full-page Livewire component)
- `public int $page = 1;` current grid page (1-indexed); `const PER_PAGE = 6;`.
- `public array $tiles = [];` populated from `ActivePublishPaths::get()`.
- `mount()` and `refresh()` reload `$tiles`; `refresh()` is driven by `wire:poll`.
- Computed: current-page slice of 6, `totalPages = max(1, ceil(count / PER_PAGE))`, `$page`
  clamped into `[1, totalPages]` after each refresh (handles streams ending and shrinking the list).
- `prevPage()` / `nextPage()` methods.
- Renders into `resources/views/layouts/monitor.blade.php` (dark, full-viewport; includes Livewire
  assets + `@vite`).

### 5. View: `resources/views/livewire/multistream-monitor.blade.php`
- Root has `wire:poll.12s="refresh"`.
- Responsive grid: 3 columns × 2 rows on desktop, collapsing to 2 / 1 columns on smaller screens.
- Per tile, keyed for morphdom stability so polling does not interrupt playback:
  ```blade
  <div wire:key="tile-{{ $tile['name'] }}">
      <header>{{ $tile['name'] }}</header>
      <iframe src="{{ route('monitor.play', ['stream' => $tile['name'], 'muted' => 'true', 'controls' => 'false', 'autoplay' => 'true']) }}"
              allow="autoplay"></iframe>
      <button>Open</button>   {{-- window.open(monitor.play?stream=…) in a new tab, full single-stream --}}
      <button>Copy</button>   {{-- navigator.clipboard.writeText(player URL) via tiny inline JS --}}
  </div>
  ```
  Note: the `clipboard-copy` Livewire event used inside the Filament panel is **not** present on this
  standalone page, so "Copy" uses a small inline `navigator.clipboard` handler.
- Empty-state ("No active publishers") when the list is empty.
- Pagination controls (prev / next + "Page X of Y") below the grid; disabled at bounds.

### 6. `App\Http\Controllers\MonitorPlayerController` (internal player)
- Auth-guarded only (no embed/referrer/direct-playback checks).
- Returns the existing `player` blade view with `embedSource = ''` (direct mode → `/api/read`
  falls back to the player URL referer, which is the app origin → allowed).
- Sets `Content-Security-Policy: frame-ancestors 'self'` on the response so only the same-origin
  monitor page can frame it.
- Reuses `reader.js` and `POST /api/read` unchanged.

### 7. Admin-panel navigation entry
Register a navigation item in the panel provider linking to `route('monitor')` with
`shouldOpenInNewTab: true`, label "Multistream Monitor", icon `heroicon-o-squares-2x2`. Visible to
all three roles (super-admin, moderator, monitoring) — i.e. any authenticated panel user.

## Data Flow

```
Admin nav (new tab) ──> GET /monitor (auth) ──> Livewire MultistreamMonitor
   wire:poll.12s ──> refresh() ──> ActivePublishPaths::get()
                                      └─> MediamtxApi::listPaths()  (GET /v3/paths/list)
                                      └─> filter ready && source, map, sort by name
                   ──> slice current 6 ──> render tiles
                          └─> each <iframe> ──> GET /monitor/play?stream=… (auth)
                                 └─> player view + reader.js ──> POST /api/read (same-origin, allowed)
                                 └─> WHEP playback
```

## Error Handling

- MediaMTX API unreachable / errors → `ActivePublishPaths::get()` returns `[]`; page renders the
  empty-state. No exception surfaces.
- A path that ends between polls drops off the next render; its keyed tile (and iframe) is removed.
- `$page` is clamped after each refresh so a shrinking list can't strand the user on an empty page.
- Unauthenticated access to `/monitor` or `/monitor/play` → redirected to the panel login by the
  auth middleware.

## Testing

- **`MediamtxApi::listPaths()`** — `Http::fake` returns a sample `/v3/paths/list` body; assert the
  request URL and returned items.
- **`ActivePublishPaths`** — mixed items (ready+source, ready+no-source, not-ready) → only live
  publisher(s) in the mapped shape; returns `[]` when the API throws.
- **`MultistreamMonitor` Livewire component** (`Livewire::test`): slices 6/page and paginates
  (e.g. 7 active → 2 pages); `$page` clamps when the list shrinks; renders one tile (with path name)
  per active publisher; empty-state when none.
- **Routes / auth**: unauthenticated `GET /monitor` and `GET /monitor/play` redirect to login;
  authenticated returns 200. `MonitorPlayerController` returns the player view for a given `stream`
  and sets `frame-ancestors 'self'`.

## Out of Scope (YAGNI)

- Configurable page size (fixed 6).
- Per-tile kick / block (lives on the existing Sessions page).
- Audio mixing, recording, layout customization, drag-reorder.
- Any new public/unauthenticated endpoint (everything new is auth-guarded).
- Changes to the public `/play` route or the read-token / player-access gates.
