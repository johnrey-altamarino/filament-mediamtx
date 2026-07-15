# WebRTC player: auto-unmute on viewer interaction (incl. canvas-overlay embeds)

## Symptom

The `/play` WebRTC player starts **muted** (required so it can autoplay). Viewers
had no way to get sound. On the game-HUD embeds (e.g.
`https://ara-provider.dotara.dev/pages/game.html?game=colorgamestudio`) the
problem is worse: the player is embedded in an `<iframe>` that the host page lays
a full-screen `<canvas>` over, so the video's own controls are unreachable and no
tap ever lands on the player.

## Root cause

Two independent facts about the game-HUD embed (from the host's `hudv5.js`):

```js
// full-viewport game canvas, painted on top
const canvas = document.createElement('canvas');
document.body.prepend(canvas);

// the /play iframe, layered UNDER the canvas
const videoFrameEl = document.createElement('iframe');
videoFrameEl.src = '.../play?stream=...';
videoFrameEl.allow = 'autoplay; fullscreen';   // ← the hook that saves us
videoFrameEl.style.pointerEvents = 'none';      // ← no tap ever reaches /play
document.body.insertBefore(videoFrameEl, canvas);
```

1. `pointer-events: none` on the iframe means **no pointer/touch event ever
   reaches the player document** — a listener inside `/play` can never fire.
2. The canvas belongs to a **cross-origin parent**, and user activation does not
   cross into a cross-origin child, so the parent can't hand the iframe a gesture
   (and we cannot edit the host repo to postMessage one in).

So an in-frame "tap to unmute" listener alone is dead on these pages. The one
usable lever is the `allow="autoplay"` the host already sets: a cross-origin
iframe carrying `allow="autoplay"` is permitted **audible** playback once the
**top page** receives any user gesture — which every viewer produces by tapping
the HUD to place bets. That gesture does not need to reach the iframe; it flips
the iframe's *delegated* autoplay policy to `"allowed"`.

## Fix

In [resources/views/player.blade.php](../resources/views/player.blade.php) the
player still starts muted (so the video always shows — no black-frame risk), then
unmutes **once** on the first sign audible playback is permitted, via two
complementary, feature-detected triggers:

1. **Direct interaction** — `pointerup` / `keydown` on `document`, `{ once: true }`.
   Covers the standalone player and ordinary embeds where taps reach the frame.
   Never fires on the canvas-overlay embed (harmless).
2. **Autoplay-policy watcher** — after playback is live (`onTrack`), poll
   `navigator.getAutoplayPolicy("mediaelement")` every 750 ms; the moment it reads
   `"allowed"`, set `muted = false` once and stop. This is what unmutes on the
   game-HUD page, right after the viewer's first tap on the canvas.

Key safety properties:

- Reading `getAutoplayPolicy` has **no side effects**. We never blind-set
  `muted = false` without permission, because Chrome punishes that by **pausing**
  the video.
- Idempotent and self-cleaning: the first trigger to fire unmutes and tears down
  the other (and the poll); `teardown()` also clears the poll.
- **No regression:** if no signal ever grants permission, the player simply stays
  muted — exactly today's behavior. Best case it auto-unmutes on first interaction.
- Guarded by the existing `muted` flag: with `?muted=false` the whole mechanism is
  skipped (nothing to unmute).

## Verify

Because this depends on real browser autoplay policy + a real cross-origin gesture,
it must be checked on a device, not in tests:

1. **Standalone:** open `/play?stream=<live>` in Chrome, click anywhere → audio
   starts.
2. **Canvas-overlay embed:** open the game-HUD page (e.g.
   `.../game.html?game=colorgamestudio`), tap the HUD once → the background video
   gains sound within ~1 s. (Confirms the `allow="autoplay"` → delegated-policy
   path.)
3. **No-regression:** if you never interact, the video keeps playing muted.

## Limitations

- `navigator.getAutoplayPolicy` ships in Chromium 110+ (the game-HUD audience).
  Firefox/Safari lack it, so on those browsers the canvas-overlay case has no
  trigger and stays muted; the direct-interaction trigger still works everywhere
  taps reach the frame.
- Requires the host iframe to carry `allow="autoplay"`. The current game-HUD
  embeds do; an embed without it cannot be auto-unmuted from our side.

## Deploy

Frontend-only change to an inline `<script>` in a Blade view — no asset build
(`npm run build`) and no migration needed. Deploy by pushing to the server and
letting the view be served as-is.
