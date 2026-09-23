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
