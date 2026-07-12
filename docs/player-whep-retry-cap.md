# WebRTC player: cap retries on "stream not found"

## Symptom

Opening the player for a stream that is offline (e.g. `play.jrta.online/testbac1`)
produced an endless flood of requests in the browser console:

```
POST https://play.jrta.online/testbac1/whep 404 (Not Found)
WebRTC error: Error: stream not found, retrying in some seconds
```

The player kept POSTing to `/whep` forever, never giving up.

## Root cause

In [resources/views/player.blade.php](../resources/views/player.blade.php), the
`MediaMTXWebRTCReader` `onError` handler always rescheduled `initPlayer()` via
`retryTimer`, with only an exponential backoff (capped at 30 s) on the delay.
When the stream is not found, MediaMTX returns `404` immediately, `onError` fires,
and the player retries — indefinitely. The backoff slowed the flood but never
stopped it.

## Fix

Added a bounded retry counter to the `onError` path:

- `errorRetries` counts consecutive WebRTC errors; `MAX_ERROR_RETRIES = 3`.
- After the 3rd retry, the player stops requesting, shows `Stream unavailable.`,
  and hides the spinner — no more `/whep` POSTs.
- The counter resets to `0` on a successful track (`onTrack`) and when the tab is
  refocused (`visibilitychange`), so a stream that comes back online can recover
  without a page reload.

The token-fetch retry (`fetchToken`, already capped at 3 attempts) and the
15 s watchdog are unaffected — a 404 fires `onError` before the watchdog, so the
watchdog is not a separate flood path.

## Verify

1. Open the player for an offline stream: `…/play?stream=<offline>`.
2. Watch the Network/Console tab: you should see at most **4** `/whep` POSTs
   (initial + 3 retries), then `Stream unavailable.` and no further requests.
3. Start publishing the stream, then refocus the tab (or reload): playback
   recovers.

## Deploy

Frontend-only change to an inline `<script>` in the Blade view. No asset rebuild
required (`reader.js` library is unchanged). `git push` to the server and let the
view cache refresh (`php artisan view:clear` if views are cached).
