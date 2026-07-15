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
unmutes **once** on the first sign audible playback is permitted, via three
feature-detected triggers wired up when playback goes live (`onTrack`):

1. **Direct interaction** — `pointerup` / `keydown` on `document`, `{ once: true }`.
   A genuine user gesture always permits sound. Covers the standalone player and
   ordinary embeds where taps reach the frame. Never fires on the canvas-overlay
   embed (harmless).
2. **Autoplay-policy check** — `navigator.getAutoplayPolicy(videoEl) === "allowed"`,
   polled cheaply. Side-effect-free; commits to sound when the policy reports it.
3. **Optimistic probe (embedded only)** — every 1.5 s, *attempt* `muted = false`
   + `play()`, then after 200 ms verify: if the element is playing and unmuted,
   keep it (sound is live); otherwise the browser denied sound (it pauses or
   re-mutes the element), so revert to muted playback and try again. Once the
   viewer taps the host page, `allow="autoplay"` delegation lets the next probe
   stick. Capped at `MAX_UNMUTE_PROBES` (~60 s) of probing, after which the quiet
   gesture + policy checks keep running indefinitely.

### Why the probe (and not the policy query alone)

The first shipped version relied **only** on trigger 2, using the *string* form
`getAutoplayPolicy("mediaelement")`. It worked standalone but **never unmuted when
embedded**. Root cause: inside a cross-origin iframe that query reflects only *this
frame's own* user activation — which never changes, because no tap reaches the
frame — so it stays `"allowed-muted"` forever. The delegated `allow="autoplay"`
grant (which depends on the *top* page's gesture) is not surfaced by that query.
Trigger 2 now uses the element form `getAutoplayPolicy(videoEl)` (more likely to
honour delegation) but is treated as best-effort; trigger 3 is what actually
carries the embedded case, by testing the real operation instead of trusting the
policy report.

### Safety properties

- The probe runs **embedded only** (`window.self !== window.top`). In the
  top-level document, unmuting before a click would make Chrome **pause** the
  video — regressing the working direct-playback case — so the probe is skipped
  there and only the gesture/policy triggers apply.
- Every failed probe reverts to muted playback within ~200 ms, so a denied attempt
  is at worst a brief hitch (often none, when the browser re-mutes rather than
  pauses). The video never gets stuck paused.
- Idempotent and self-cleaning: the first trigger to succeed unmutes and tears
  down the timer + listeners; `teardown()` also clears them.
- **No regression:** if no signal ever grants permission, the player stays muted —
  exactly the prior behavior. Guarded by the existing `muted` flag, so `?muted=false`
  skips the whole mechanism.

### Debugging

Set `localStorage.unmuteDebug = "1"` on the player origin (open
`https://studio.jrta.online/play` directly and run it in the console — localStorage
is shared with the embedded iframe on the same origin), then reload the embed. The
player logs `[player:unmute] …` lines: whether it's embedded, whether the policy
API exists, and each probe's outcome.

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
