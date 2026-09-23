# Monitor tiles: blurred background disabled

## Symptom

Streams on `/monitor` felt laggy.

## Root cause (client side)

The shared player (`resources/views/player.blade.php`) draws each stream twice: the sharp `#myvideo`, plus a
full-size `#bg-video` copy behind it with `blur(40px) scale(1.15)` to fill the letterbox gaps. The blur is recalculated
on every frame. With 6 tiles on screen, the monitor was rendering 12 videos, and 6 of them were GPU-heavy blurs. The
monitor gains nothing from the blur.

## Fix

- `player.blade.php`: a new `bg=0` query flag turns the backdrop off:
  `const useBlurBg = objectFit !== "cover" && params.get("bg") !== "0";`.
  When it is off, the stream is never attached to `#bg-video`, so there is no second decode and no blur.
- `multistream-monitor.blade.php`: the tile iframes pass `'bg' => '0'`.
- The public `/play` page and the monitor's "Copy link" URL do not pass the flag, so they keep the blurred backdrop.

## Network stats at the time (for reference)

`chrome://webrtc-internals` → `inbound-rtp (video)` for one tile showed a healthy stream:

| Metric | Value | Meaning |
|---|---|---|
| `packetsLost` / `nackCount` / `freezeCount` | 0 / 0 / 0 | No network loss and no freezes |
| `framesDropped` | 0 | The decoder kept up (hardware VideoToolbox, 2.25 ms/frame) |
| `framesPerSecond` | 30 (inter-frame σ 5.5 ms) | Smooth delivery |
| jitter buffer | ~47 ms | Very low playout delay |
| bitrate | ~1.9 Mbps, 1280×720 H.264 baseline | |
| keyframes | 113 in 6759 frames → every ~2 s | |

So the transport was not the cause of the lag. Any remaining choppiness is either rendering (fixed here) or **already
in the source video**. For example, the `monitoring` path is a screen capture of a page that itself plays 5 streams.
Stutter on the capturing machine is encoded into the stream, and 1.9 Mbps is low for a 720p mosaic.

## Verify

- `php artisan test --filter Monitor` passes (includes `test_tile_players_disable_the_blurred_background`).
- On `/monitor`, the dark bars beside the video are now plain black, not blurred.
- DevTools → Performance: GPU and raster time per frame drops noticeably with several tiles open.

## Deploy

This is a Blade and inline-JS change only, with no asset rebuild:

```bash
git pull
sudo -u www-data php artisan view:clear
```
