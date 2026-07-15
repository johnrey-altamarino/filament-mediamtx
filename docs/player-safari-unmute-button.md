# Safari Manual Unmute Button

## Symptom

In the embedded game-HUD player, audio auto-unmutes on Chrome but **never on
Safari** — the stream stays muted forever. Reported for the iframe embed where
the video sits behind the host page's betting controls.

## Root cause

The player starts muted (so muted autoplay is allowed) and relies on three
auto-unmute triggers (`resources/views/player.blade.php`, "Auto-unmute" block):

1. **Gesture** — a `pointerup`/`keydown` reaching the player's own document.
2. **Policy** — `navigator.getAutoplayPolicy(video) === "allowed"`.
3. **Probe** (embedded only) — optimistically `muted=false; play()` and keep it
   if it sticks; relies on the host's delegated `allow="autoplay"`.

On Safari all three fail for this embed:

- **Trigger 1** never fires — viewers tap the parent page's betting controls, not
  the iframe, so no gesture reaches the player's document.
- **Trigger 2** is dead — `getAutoplayPolicy` is **Firefox-only**; it doesn't
  exist in Safari (or Chrome).
- **Trigger 3** is **denied by WebKit** — Safari refuses script-initiated unmute
  for an embedded iframe based on the *parent's* activation. Unlike Chrome, it
  requires a genuine user gesture **inside the iframe's own document**. The probe
  gets reverted to muted every cycle.

Chrome only works because of Trigger 3 + Chrome's delegated-activation courtesy,
which Safari deliberately does not grant.

## Fix

Surface a manual **"Tap for sound"** button, shown **only on Safari**, once
playback starts while still muted. A tap on it is a real in-frame gesture — the
one thing WebKit requires — so it unmutes reliably.

`resources/views/player.blade.php`:

- Added `#unmute-btn` (speaker-off icon + "Tap for sound"), a bottom-right pill,
  hidden by default.
- `IS_SAFARI` detection (desktop Safari + iOS/iPadOS WebKit, excluding
  Chrome/Edge/Opera/Android).
- `maybeShowUnmuteButton()` — reveals the button when `IS_SAFARI && muted &&
  !unmuted`; called from the `playing` event handler.
- Button `click` → `doUnmute("button")`. `doUnmute()` (and the probe-success
  path) hide the button, so if any auto-unmute trigger ever does succeed the
  button disappears on its own.

Non-Safari browsers are unaffected — the button never shows there and auto-unmute
continues to work via the probe.

## Verify

Manual, on Safari (this is WebKit-specific; the repo has only PHP/Pest tests):

1. Open the embedded player in **desktop Safari** and **iOS Safari** with sound
   muted (the normal embed state).
2. Once the video is playing, a "Tap for sound" pill appears bottom-right.
3. Tap it → audio starts and the button disappears.
4. On Chrome/Firefox, confirm the button does **not** appear and auto-unmute
   still works as before.

> Caveat: the button only helps if taps actually reach the iframe. If the host
> page overlays a full-cover, tap-capturing element over the video area, even the
> in-frame button won't receive the tap — in that case the host must let taps
> through to the video region (or forward the gesture). See
> `docs/player-auto-unmute.md` for the auto-unmute design.
