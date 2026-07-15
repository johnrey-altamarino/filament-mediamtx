# "Tap to play" Overlay Stuck on iOS After Playing

## Symptom

On iPhone (iOS Safari), tapping the "Tap to play" overlay starts the stream,
but the play-button overlay stays on top of the now-playing video instead of
disappearing. Reproduced in the game-HUD iframe embed (`autoplay=true`,
`controls=false`, muted start).

## Root cause

The tap overlay was hidden in only one place — the `.then()` of `videoEl.play()`
inside the overlay's `click` handler:

```js
tapOverlay.addEventListener("click", () => {
  videoEl.play()
    .then(() => tapOverlay.classList.add("hidden"))
    .catch(() => {});   // rejection swallowed → overlay never hides
});
```

On iOS a single tap dispatches `pointerup` **before** `click`. The auto-unmute
watcher registers a `pointerup` listener (`unmuteFromGesture` → `doUnmute`) that
sets `videoEl.muted = false` and calls `videoEl.play()`. So one tap triggers two
overlapping `play()` calls:

1. `pointerup` → `doUnmute()` → `videoEl.play()`  (call A)
2. `click`    → overlay handler → `videoEl.play()` (call B, gated to hide overlay)

iOS Safari rejects one of two racing `play()` requests with an "interrupted"
`AbortError`. When call B loses the race, its promise rejects, the `.catch()`
swallows it, `.then()` never runs, and the overlay stays visible even though the
video is playing.

The `playing` media event — the authoritative "playback actually started"
signal — only called `showLoader(false)` and never touched the overlay, so there
was no fallback.

## Fix

`resources/views/player.blade.php`: hide the tap overlay on the `playing` event,
decoupling overlay dismissal from the fragile `play()` promise:

```js
videoEl.addEventListener("playing", () => {
  showLoader(false);
  tapOverlay.classList.add("hidden");
});
```

The click handler's `.then()` is left in place as a fast path; it's now just an
optimization, not the only way the overlay hides. Because `playing` fires
whenever the element actually reaches the playing state regardless of which
`play()` call started it (autoplay, auto-unmute, or the tap), the overlay is now
reliably cleared.

## Verify

Manual, on a physical iPhone (this is iOS-Safari-specific event-race behavior;
the repo has only PHP/Pest tests and no JS/browser harness to reproduce it):

1. Open the embed on iPhone Safari where the "Tap to play" overlay appears.
2. Tap the overlay. The stream should start **and** the play button + "Tap to
   play" text should disappear immediately.
3. Confirm audio still auto-unmutes on that same tap (unchanged behavior).
