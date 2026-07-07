# Multistream Monitor — Tile Controls Redesign

## Symptom / Request

The per-tile controls on the `/monitor` grid were awkward:

- Each tile had a **top header bar** repeating the path name (left) and viewer count (right).
- The action row had a text **"Open"** link that launched the stream in a **new browser tab**, plus a text **"Copy"** button.

Requested changes:

1. Replace the "Open" text with the **stream (path) name**.
2. Replace the "Copy" text with a **copy icon**.
3. Clicking the path name should **expand the tile to fill the whole page on the same tab** (no new tab), with a **Back** button top-left.
4. **Remove** the path name from the top of each frame.
5. **Move the viewer count to the bottom-right** of the tile.

## Fix

All changes are view-only, in
[`resources/views/livewire/multistream-monitor.blade.php`](../resources/views/livewire/multistream-monitor.blade.php)
plus a small script + CSS in the layout.

- Removed the top header bar entirely.
- Each tile now carries Alpine state: `x-data="{ expanded: false, copied: false, muted: true }"`.
- **Path name button** (`@click="expanded = true"`) sits in the bottom bar, styled as a
  boxed button (`bg-neutral-800 border`), and when clicked toggles the tile to
  `fixed inset-0 z-50` so it fills the viewport. The video container switches from
  `aspect-video` to `flex-1` while expanded. Because the `<iframe>` lives inside a
  `wire:ignore` block and the tile keeps a stable `wire:key`, the stream keeps playing
  across both the expand and the 12s `wire:poll` re-renders (Alpine state is preserved
  through Livewire morphs).
- **Back button** (`x-show="expanded"`) overlays top-left and sets `expanded = false`.
- **Copy button** is now an icon that swaps to a green check for ~1.2s on a *successful*
  copy, driven by the `copied` flag. The URL is emitted safely via the `@js(...)` Blade
  directive (route name `monitor.play`) inside the Alpine `@click` handler.
- **Mute / unmute button** — a speaker icon that toggles the tile's audio. The player is
  same-origin, so the click reaches into the iframe and flips the `<video id="myvideo">`
  element directly: `$refs.frame.contentWindow.document.getElementById('myvideo').muted = muted`.
  No stream reload — audio flips instantly, and the real user gesture satisfies the browser's
  autoplay-with-sound policy. Wrapped in `try/catch` so it's a no-op if the frame isn't ready.
  The iframe carries `x-ref="frame"` and streams start muted (`muted=true` in the src), matching
  the initial `muted: true` state.
- **Viewer count** moved to the bottom bar, pushed right with `ml-auto`.

### Copy over plain HTTP

`navigator.clipboard` only exists in a **secure context** (HTTPS or localhost). This
deployment serves `/monitor` over plain HTTP, where `navigator.clipboard` is `undefined`
and calling `writeText()` throws — so the copy button silently did nothing. A
`window.monitorCopy(text)` helper in
[`resources/views/layouts/monitor.blade.php`](../resources/views/layouts/monitor.blade.php)
uses `navigator.clipboard` when available and otherwise falls back to a hidden `<textarea>`
+ `document.execCommand('copy')`. It returns a `Promise<boolean>`; the tile only shows the
green check when the copy actually succeeded.

### x-cloak

Added `[x-cloak] { display: none !important; }` to
[`resources/views/layouts/monitor.blade.php`](../resources/views/layouts/monitor.blade.php)
so the Back button and copy-confirmation check don't flash on first paint before Alpine
initializes. Alpine ships with `@livewireScripts` (Livewire v3), so no extra script is needed.

## Verify

- Compiled the Blade template and linted the resulting PHP — no syntax errors.
- Open `/monitor`: each tile shows video, then a bottom bar with
  `path name · copy icon · speaker icon · N viewers`.
- Click the path name → tile fills the page, Back button appears top-left; click Back → returns to grid. Stream never reloads.
- Click the copy icon → clipboard gets the `monitor.play` URL (works over HTTP too); icon flips to a check briefly.
- Click the speaker icon → audio toggles instantly without reloading the stream; icon reflects muted/unmuted.

## Deploy

View-only change. `git push` to the server, then clear the compiled views:

```bash
php artisan view:clear
```

No migration, asset rebuild, or config change required.
