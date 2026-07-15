# Player Pulsing Loading Image

Replaces the CSS border spinner in the WebRTC player with a custom pulsing image.

## Symptom / Request

The loading indicator was a plain rotating border spinner. Wanted a branded
loading graphic (`loading-image.png`) that pulses instead.

## Change

`resources/views/player.blade.php`:

- The loader element changed from a `<div id="spinner" class="spinner">` to an
  `<img id="spinner" class="spinner" src="/img/loading-image.png">`. The `id`
  is preserved so the existing `showLoader()` JS (which toggles the `.hidden`
  class on `#spinner`) keeps working unchanged.
- `.spinner` CSS swapped from the `spin` rotation to a `pulse` keyframe that
  animates `opacity` (0.45 → 1) and `transform: scale` (0.92 → 1) on a 1.2s
  ease-in-out loop.

## Asset location

The player references public-root assets (e.g. `/favicon.ico`), so the image
must live under `public/`. The source lives at `resources/img/loading-image.png`
and is copied to `public/img/loading-image.png`, referenced as
`/img/loading-image.png`.

> Note: `loading-image.png` is ~2.3 MB. Consider compressing/resizing it for
> faster first paint on the player.

## Verify

1. Open the player page.
2. During WHEP connect / buffering the loading image should be visible and
   pulsing (fading + gently scaling), and disappear once playback starts.
