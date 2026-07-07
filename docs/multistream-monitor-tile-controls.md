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
plus one CSS line in the layout.

- Removed the top header bar entirely.
- Each tile now carries Alpine state: `x-data="{ expanded: false, copied: false }"`.
- **Path name button** (`@click="expanded = true"`) sits in the bottom bar and, when
  clicked, toggles the tile to `fixed inset-0 z-50` so it fills the viewport. The video
  container switches from `aspect-video` to `flex-1` while expanded. Because the `<iframe>`
  lives inside a `wire:ignore` block and the tile keeps a stable `wire:key`, the stream
  keeps playing across both the expand and the 12s `wire:poll` re-renders (Alpine state is
  preserved through Livewire morphs).
- **Back button** (`x-show="expanded"`) overlays top-left and sets `expanded = false`.
- **Copy button** is now an icon that swaps to a green check for ~1.2s on click, driven by
  the `copied` flag. The URL is emitted safely via the `@js(...)` Blade directive inside the
  Alpine `@click` handler.
- **Viewer count** moved to the bottom bar, pushed right with `ml-auto`.

### x-cloak

Added `[x-cloak] { display: none !important; }` to
[`resources/views/layouts/monitor.blade.php`](../resources/views/layouts/monitor.blade.php)
so the Back button and copy-confirmation check don't flash on first paint before Alpine
initializes. Alpine ships with `@livewireScripts` (Livewire v3), so no extra script is needed.

## Verify

- Compiled the Blade template and linted the resulting PHP — no syntax errors.
- Open `/monitor`: each tile shows video, then a bottom bar with `path name · copy icon · N viewers`.
- Click the path name → tile fills the page, Back button appears top-left; click Back → returns to grid. Stream never reloads.
- Click the copy icon → clipboard gets the `monitor.play` URL; icon flips to a check briefly.

## Deploy

View-only change. `git push` to the server, then clear the compiled views:

```bash
php artisan view:clear
```

No migration, asset rebuild, or config change required.
