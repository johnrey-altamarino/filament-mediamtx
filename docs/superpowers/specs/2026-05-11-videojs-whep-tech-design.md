# Video.js Custom WHEP Tech — Design Spec

**Date:** 2026-05-11  
**Status:** Approved

## Goal

Wrap the existing `MediaMTXWebRTCReader` (WHEP/WebRTC) in a custom Video.js tech so the player gets Video.js's control bar UI while keeping WebRTC's low-latency transport. Fullscreen and picture-in-picture remain disabled (matching the current player).

## Files Changed

| File | Change |
|---|---|
| `public/player.html` | Replace inline script + bare `<video>` tag with Video.js CDN + init script |
| `public/whep-tech.js` | New — `MediamtxWhepTech` custom tech, registers with `videojs` |
| `public/reader.js` | Add missing `updateToken(token)` method (bug fix — called by player but not defined) |

## Data Flow

```
player.html loads
  → Video.js CDN CSS + JS
  → reader.js  (defines MediaMTXWebRTCReader)
  → whep-tech.js  (registers MediamtxWhepTech)

videojs('myvideo', { techOrder: ['MediamtxWhep'] })
  → Video.js renders control bar
  → MediamtxWhepTech.createEl() creates <video> element
  → play() → fetchToken() → new MediaMTXWebRTCReader(...)
  → onTrack: video.srcObject = stream → trigger loadstart/canplay/playing
  → setInterval 45s → fetchToken() → reader.updateToken(token)
  → beforeunload → player.dispose() → reader.close()
```

## Script Loading Order

Scripts placed at the bottom of `<body>` (no `defer`) to guarantee globals exist before each dependent script runs:

1. `video.min.js` (CDN) — defines `window.videojs`
2. `reader.js` — defines `window.MediaMTXWebRTCReader`
3. `whep-tech.js` — registers `MediamtxWhepTech` with `videojs`
4. Inline init script — calls `videojs(...)`

## `MediamtxWhepTech` Interface

| Method | Behavior |
|---|---|
| `createEl()` | Creates `<video>` with `autoplay`, `playsinline`, `muted`, `disablePictureInPicture` |
| `play()` | Fetches JWT → inits `MediaMTXWebRTCReader` → starts 45s refresh interval |
| `pause()` | No-op (live stream) |
| `paused()` | Always `false` |
| `duration()` | Returns `Infinity` (live stream signal to Video.js) |
| `currentTime()` | Returns `0` |
| `setCurrentTime()` | No-op |
| `muted()` / `setMuted()` | Proxied to `<video>` element |
| `volume()` / `setVolume()` | Proxied to `<video>` element |
| `supportsFullScreen()` | Returns `false` |
| `dispose()` | Clears interval → `reader.close()` → `super.dispose()` |

**Static methods (required by Video.js tech API):**

- `isSupported()` → `typeof RTCPeerConnection !== 'undefined'`
- `canPlayType()` → `'maybe'`
- `canPlaySource(source)` → `'maybe'` if `source.type === 'video/whep'`, else `''`

**Events fired into Video.js on stream connect:** `loadstart`, `canplay`, `playing`

## Video.js Config

```js
videojs('myvideo', {
  techOrder: ['MediamtxWhep'],
  controls: true,
  autoplay: true,
  muted: true,
  sources: [{ type: 'video/whep', src: 'whep' }],
  controlBar: {
    fullscreenToggle: false,
    pictureInPictureToggle: false,
  },
});
```

## Video.js Version

Load via CDN: `video.js@8` (latest stable v8)

- CSS: `https://cdn.jsdelivr.net/npm/video.js@8/dist/video-js.min.css`
- JS: `https://cdn.jsdelivr.net/npm/video.js@8/dist/video.min.js`

## Future Migration to Vite

When moving to a build pipeline:

1. `player.html` → `resources/views/player.blade.php`
2. Add route: `Route::get('/player', fn() => view('player'))`
3. Move `whep-tech.js` → `resources/js/whep-tech.js`, import via `@vite`
4. Install Video.js via npm: `npm install video.js`
5. Import in JS: `import videojs from 'video.js'`

## reader.js Bug Fix

`updateToken(token)` is called in the token refresh loop but never defined. Add:

```js
updateToken(token) {
  this.conf.token = token;
}
```

The `#authHeader()` method already reads `this.conf.token` at call time, so updating the conf object is sufficient.
