# Video.js Custom WHEP Tech Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wrap `MediaMTXWebRTCReader` in a custom Video.js tech so the player gets Video.js's control bar UI while keeping WebRTC/WHEP low-latency transport.

**Architecture:** A new `public/whep-tech.js` file registers `MediamtxWhepTech` (extends `videojs.getTech('Tech')`). The tech owns the `<video>` element, fetches the JWT, initialises `MediaMTXWebRTCReader`, and manages the 45-second token refresh cycle. `player.html` is updated to load Video.js from CDN and initialise the player using this tech. No build step required.

**Tech Stack:** Video.js 8 (CDN), `MediaMTXWebRTCReader` (existing `reader.js`), plain HTML/JS

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `public/reader.js` | Modify | Add missing `updateToken(token)` public method |
| `public/whep-tech.js` | Create | `MediamtxWhepTech` class + Video.js registration |
| `public/player.html` | Modify | Video.js CDN links, updated `<video>` tag, new init script |

---

## Task 1: Fix missing `updateToken` in `reader.js`

**Files:**
- Modify: `public/reader.js` (after line 55, inside the `MediaMTXWebRTCReader` class, after the `close()` method)

**Context:** `player.html` already calls `reader.updateToken(token)` in the token refresh interval, but this method was never defined. The `#authHeader()` private method reads `this.conf.token` at call time, so updating the conf property is sufficient.

- [ ] **Step 1: Add `updateToken` after the `close()` method**

Open `public/reader.js`. After the closing brace of the `close()` method (line 55), add:

```js
  /**
   * Update the auth token used for subsequent requests.
   * @param {string} token - new bearer token.
   */
  updateToken(token) {
    this.conf.token = token;
  }
```

The result should look like:

```js
  close() {
    this.state = 'closed';
    // ... existing body ...
  }

  updateToken(token) {
    this.conf.token = token;
  }

  static #supportsNonAdvertisedCodec(codec, fmtp) {
```

- [ ] **Step 2: Verify the method is reachable (manual check)**

Open browser dev tools console on any page that loads `reader.js` and run:

```js
const r = new MediaMTXWebRTCReader({ url: '', onError: () => {}, onTrack: () => {} });
r.updateToken('test');
console.log(r.conf.token); // should print "test"
r.close();
```

Expected: prints `"test"` with no errors.

- [ ] **Step 3: Commit**

```bash
git add public/reader.js
git commit -m "fix: add missing updateToken method to MediaMTXWebRTCReader"
```

---

## Task 2: Create `public/whep-tech.js`

**Files:**
- Create: `public/whep-tech.js`

**Context:** This file must be loaded **after** `video.min.js` (CDN) and `reader.js`, both of which define globals (`window.videojs`, `window.MediaMTXWebRTCReader`). It wraps itself in an IIFE to avoid polluting global scope. The tech reads `?stream=` from the URL query string — the same way the existing `player.html` does.

- [ ] **Step 1: Create `public/whep-tech.js` with the full content below**

```js
'use strict';

(function () {
  const Tech = videojs.getTech('Tech');

  class MediamtxWhepTech extends Tech {
    constructor(options, ready) {
      super(options, ready);
      this._reader = null;
      this._token = null;
      this._tokenInterval = null;
      this._stream = new URLSearchParams(window.location.search).get('stream');
      this._whepUrl = `https://stream.jrta.online/${this._stream}/whep`;
    }

    createEl() {
      return videojs.dom.createEl('video', {
        className: 'vjs-tech',
        tabIndex: -1,
      }, {
        autoplay: true,
        playsinline: '',
        muted: true,
        disablePictureInPicture: true,
      });
    }

    async play() {
      if (this._reader) return;
      try {
        this._token = await this._fetchToken();
        this._initReader();
        this._tokenInterval = setInterval(async () => {
          try {
            this._token = await this._fetchToken();
            if (this._reader) this._reader.updateToken(this._token);
          } catch (e) {
            console.error('Token refresh failed:', e);
          }
        }, 45000);
      } catch (err) {
        console.error('Player init failed:', err);
        this.trigger('error');
      }
    }

    pause() {}

    paused() { return false; }

    duration() { return Infinity; }

    currentTime() { return 0; }

    setCurrentTime() {}

    muted() { return this.el_.muted; }

    setMuted(muted) { this.el_.muted = muted; }

    volume() { return this.el_.volume; }

    setVolume(vol) { this.el_.volume = vol; }

    supportsFullScreen() { return false; }

    dispose() {
      clearInterval(this._tokenInterval);
      if (this._reader) {
        this._reader.close();
        this._reader = null;
      }
      super.dispose();
    }

    async _fetchToken() {
      const res = await fetch('https://platform.jrta.online/api/read', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        credentials: 'include',
        body: JSON.stringify({ path: this._stream }),
      });
      const data = await res.json();
      if (!data.token) throw new Error('JWT not received');
      return data.token;
    }

    _initReader() {
      this._reader = new MediaMTXWebRTCReader({
        url: this._whepUrl,
        token: this._token,
        onError: (err) => {
          console.error('WebRTC error:', err);
        },
        onTrack: (evt) => {
          this.el_.srcObject = evt.streams[0];
          this.trigger('loadstart');
          this.trigger('canplay');
          this.trigger('playing');
        },
      });
    }
  }

  MediamtxWhepTech.isSupported = () => typeof RTCPeerConnection !== 'undefined';
  MediamtxWhepTech.canPlayType = () => 'maybe';
  MediamtxWhepTech.canPlaySource = (source) => source.type === 'video/whep' ? 'maybe' : '';

  videojs.registerTech('MediamtxWhep', MediamtxWhepTech);
}());
```

- [ ] **Step 2: Verify the file parses without errors (manual check)**

Open browser dev tools, load `video.min.js` and `reader.js` first (or temporarily paste them into the console), then paste the contents of `whep-tech.js`. Expected: no errors, and `videojs.getTech('MediamtxWhep')` returns the class.

- [ ] **Step 3: Commit**

```bash
git add public/whep-tech.js
git commit -m "feat: add MediamtxWhepTech custom Video.js tech for WHEP playback"
```

---

## Task 3: Update `public/player.html`

**Files:**
- Modify: `public/player.html`

**Context:** Replace the entire file. Key changes:
- Add Video.js CDN CSS in `<head>`
- Remove the old `<script defer src="./reader.js">` from `<head>` (scripts move to bottom of `<body>` so globals are available in order)
- Replace bare `<video>` with a `video-js`-classed element
- Load scripts at bottom of `<body>` in order: `video.min.js` → `reader.js` → `whep-tech.js` → inline init
- Inline init calls `videojs()` with `techOrder: ['MediamtxWhep']` and disables fullscreen + PiP toggles

- [ ] **Step 1: Replace `public/player.html` with the content below**

```html
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>MediaMTX WebRTC Player</title>
<link href="https://cdn.jsdelivr.net/npm/video.js@8/dist/video-js.min.css" rel="stylesheet">
<style>
  html, body {
    margin: 0;
    padding: 0;
    height: 100%;
    width: 100%;
    background: black;
    display: flex;
    justify-content: center;
    align-items: center;
  }
  .video-js {
    width: 100%;
    height: 100%;
  }
</style>
</head>
<body>

<video id="myvideo" class="video-js vjs-default-skin"></video>

<script src="https://cdn.jsdelivr.net/npm/video.js@8/dist/video.min.js"></script>
<script src="./reader.js"></script>
<script src="./whep-tech.js"></script>
<script>
  const player = videojs('myvideo', {
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

  window.addEventListener('beforeunload', () => player.dispose());
</script>

</body>
</html>
```

- [ ] **Step 2: Open the player in a browser and verify**

Navigate to `https://platform.jrta.online/player.html?stream=<a-live-stream-name>` (or your local dev URL).

Expected:
- Video.js control bar appears at the bottom (play button, volume, time display)
- No fullscreen button visible in the control bar
- No picture-in-picture button visible
- Stream connects and video plays within ~2 seconds
- Browser console shows no errors

- [ ] **Step 3: Verify token refresh (optional — wait 45s)**

After the stream connects, wait 45 seconds and check the browser network tab. Expected: a new `POST /api/read` request fires, returning a fresh JWT.

- [ ] **Step 4: Verify cleanup on tab close**

Open the page, let it connect, then close the tab. In the MediaMTX admin panel (`/sessions`), confirm the session is removed within ~20 seconds (MediaMTX session timeout) — this confirms `reader.close()` fired via `beforeunload`.

- [ ] **Step 5: Commit**

```bash
git add public/player.html
git commit -m "feat: integrate Video.js UI with MediamtxWhepTech for WebRTC playback"
```
