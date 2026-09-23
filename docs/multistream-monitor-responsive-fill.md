# Multistream Monitor: responsive fill + click-to-expand

## Symptom

- With one or two active streams, `/monitor` still used the 3-column 16:9 grid. The video filled only a small corner
  of the screen, and the rest was empty.
- The "Multistream Monitor" heading took up vertical space without adding anything useful.
- To view one stream full page you had to click the small path-name button. Clicking the video did nothing, because
  the `<iframe>` swallowed the click.

## Root cause

The grid classes were fixed (`grid-cols-1 sm:grid-cols-2 lg:grid-cols-3` plus `aspect-video` per tile), whatever
the tile count. The tile's only expand trigger was the path-name button.

## Fix

All changes are in `resources/views/livewire/multistream-monitor.blade.php`:

- **Heading removed.** A slim top bar keeps only the `N active` count and **Logout**.
- **Fill mode** (`$fill`) applies when the current page shows 1–2 tiles and there is only one page. The root becomes
  `h-dvh flex flex-col`, the grid becomes `flex-1 min-h-0`, and each tile's video area is `flex-1 min-h-0`, not
  `aspect-video`. The player inside the iframe letterboxes with `object-fit: contain`.
  - **1 stream** fills the whole viewport.
  - **2 streams** sit side by side in landscape (`landscape:grid-cols-2`) and stack in portrait
    (`portrait:grid-rows-2`), so they fill the screen on both desktop and phone.
- **3+ streams** keep the existing responsive 16:9 grid.
- **Click the video to expand.** A transparent click-catcher button (`absolute inset-0`, `cursor-zoom-in`) now sits
  over each iframe. It shows only while the tile is collapsed, and it sets `expanded = true`, which uses the existing
  `fixed inset-0 z-50` full-page mode. Once the tile is expanded, the catcher is hidden, so the player's own
  controls work again. The path-name button still expands too.
- **Esc** closes the expanded view, and so does **Back**.

The iframes are still `wire:ignore`, so expanding and collapsing a tile, and the 12s poll, never reload a stream.

## Verify

Rendered with 1, 2 and 4 mocked streams at 1280×720 and 390×844:

- 1 stream: the tile fills everything below the top bar.
- 2 streams: two columns in landscape, two rows in portrait, and neither scrolls.
- 4 streams: unchanged 3-column grid.
- Clicking a video expands it to full page, and Esc or Back returns to the grid.

`php artisan test --filter Monitor` passes.

## Deploy

Assets changed (new Tailwind classes), so the build must run:

```bash
git pull
npm ci && npm run build
sudo -u www-data php artisan view:clear
```

## Follow-up: overlay bar in the expanded view

**Symptom:** the expanded stream had wide blurred gaps on the left and right. The stream is 16:9, and the space
left above the bottom bar was about 2:1.

**Fix:** in the expanded view, the bottom bar (name / copy / mute / viewers) now floats over the video
(`absolute bottom-0`, translucent), so the video gets the full viewport height. The gaps shrink but do not disappear
entirely. They are what's left of the gap between 16:9 and the screen's aspect ratio. Stretching or cropping would
remove them, but both options were rejected (distortion / cutting off the top and bottom).

- The bar and the Back button **auto-hide after 3s idle**. Moving the mouse or tapping brings them back. The iframe
  swallows pointer events, so `expand()` attaches `mousemove`/`touchstart`/`click` listeners to the same-origin
  player document (the same access the mute toggle already uses).
- Page scrolling is locked while a stream is expanded (`documentElement.style.overflow = 'hidden'`), so no
  scrollbar shows beside the stream. Back or Esc restores it.
- The grid view is unchanged: the bar stays below each tile there.

**Verified** at 1000×526 with a 16:9 fake player: the video spans the full height, the bar is visible after mouse
movement (opacity 1) and hidden 3s later (opacity 0), and page overflow resets after Esc.
