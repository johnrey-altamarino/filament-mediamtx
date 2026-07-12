<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/x-icon" href="/favicon.ico">
@if($whepBaseUrl)
    <link rel="preconnect" href="{{ $whepBaseUrl }}" crossorigin>
@endif
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>WebRTC Player</title>
@vite('resources/js/reader.js')
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
  video {
    width: 100%;
    height: 100%;
    object-fit: contain;
    background: black;
  }
  #loader {
    position: absolute;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    pointer-events: none;
  }
  #spinner.hidden { display: none; }
  #status {
    color: #ccc;
    font-family: sans-serif;
    font-size: 0.9rem;
    pointer-events: none;
    text-align: center;
  }
  #status.error {
    color: #ff6b6b;
  }
  .spinner {
    width: 36px;
    height: 36px;
    border: 3px solid rgba(255,255,255,0.12);
    border-top-color: rgba(255,255,255,0.55);
    border-radius: 50%;
    animation: spin 0.75s linear infinite;
    display: inline-block;
  }
  @keyframes spin { to { transform: rotate(360deg); } }
  #tap-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,0.45);
    cursor: pointer;
  }
  #tap-overlay.hidden { display: none; }
  #tap-overlay svg {
    width: 60px;
    height: 60px;
    fill: rgba(255,255,255,0.85);
  }
  #tap-overlay span {
    color: rgba(255,255,255,0.65);
    font-family: sans-serif;
    font-size: 0.85rem;
    margin-top: 8px;
  }
</style>
</head>
<body>

<video id="myvideo" playsinline disablepictureinpicture></video>
<div id="loader">
  <div id="spinner" class="spinner"></div>
  <div id="status"></div>
</div>
<div id="tap-overlay" class="hidden">
  <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M8 5v14l11-7z"/></svg>
  <span>Tap to play</span>
</div>

<script>
const WHEP_BASE_URL = "{{ $whepBaseUrl }}";
const EMBED_SOURCE = @json($embedSource);

let reader = null;
let token = null;
let retryDelay = 2000;
let errorRetries = 0;
const MAX_ERROR_RETRIES = 3;
let refreshTimer = null;
let watchdogTimer = null;
let retryTimer = null;
let starting = false;
let tokenAbort = null;
let tearingDown = false;

const statusEl = document.getElementById("status");
const videoEl = document.getElementById("myvideo");
const spinnerEl = document.getElementById("spinner");
const tapOverlay = document.getElementById("tap-overlay");

const params = new URLSearchParams(window.location.search);
const stream = params.get("stream");
const autoplay = params.get("autoplay") !== "false";
const controls = params.get("controls") === "true";
const muted = params.get("muted") !== "false";

if (autoplay) videoEl.setAttribute("autoplay", "");
if (controls) videoEl.setAttribute("controls", "");
if (muted) videoEl.muted = true;

if (!stream) {
  document.body.innerHTML = '<p style="color:#fff;font-family:sans-serif;text-align:center;margin-top:2rem">Missing required <code>?stream=</code> parameter.</p>';
  throw new Error("Missing stream parameter");
}

const whepUrl = `${WHEP_BASE_URL}/${stream}/whep`;

function setStatus(msg, isError = false) {
  statusEl.textContent = msg;
  statusEl.classList.toggle("error", isError);
}
function showLoader(v) { spinnerEl.classList.toggle("hidden", !v); }

function startWatchdog() {
  clearWatchdog();
  watchdogTimer = setTimeout(() => {
    console.warn("WebRTC watchdog: no track received, retrying");
    if (reader) { reader.close(); reader = null; }
    tokenPromise = fetchToken();
    starting = false;
    initPlayer();
  }, 15_000);
}

function clearWatchdog() {
  if (watchdogTimer) { clearTimeout(watchdogTimer); watchdogTimer = null; }
}

videoEl.addEventListener("playing", () => showLoader(false));
videoEl.addEventListener("waiting", () => showLoader(true));

tapOverlay.addEventListener("click", () => {
  videoEl.play()
    .then(() => tapOverlay.classList.add("hidden"))
    .catch(() => {});
});

async function fetchToken() {
  for (let attempt = 0; attempt < 3; attempt++) {
    if (tearingDown) throw new DOMException("torn down", "AbortError");
    if (attempt > 0) await new Promise(r => setTimeout(r, 1500 * attempt));

    tokenAbort = new AbortController();
    try {
      const res = await fetch("{{ $tokenEndpoint }}", {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        credentials: "include",
        body: JSON.stringify({ path: stream, source: EMBED_SOURCE }),
        signal: tokenAbort.signal,
      });

      // Retry on server errors only
      if (res.status >= 500 && attempt < 2) continue;
      if (!res.ok) throw new Error(`Token request denied (${res.status})`);

      const data = await res.json();
      if (!data.token) throw new Error("JWT not received");

      token = data.token;

      if (refreshTimer) clearTimeout(refreshTimer);
      if (data.expires_at) {
        const msUntilRefresh = data.expires_at * 1000 - Date.now() - 10_000;
        if (msUntilRefresh > 0) {
          refreshTimer = setTimeout(async () => {
            try { tokenPromise = fetchToken(); await tokenPromise; } catch (err) { console.error("Token refresh failed:", err); }
          }, msUntilRefresh);
        }
      }
      return;
    } catch (err) {
      // Page/iframe is going away — stop cleanly, don't retry.
      if (err.name === "AbortError") throw err;
      // Don't retry auth denials
      if (err.message.startsWith("Token request denied") || attempt === 2) throw err;
    }
  }
}

let tokenPromise = fetchToken();

async function initPlayer() {
  if (starting) return;
  starting = true;

  setStatus("Connecting…");
  showLoader(true);
  startWatchdog();

  try {
    await tokenPromise;
  } catch (err) {
    clearWatchdog();
    setStatus(`Error: ${err.message}`, true);
    showLoader(false);
    starting = false;
    return;
  }

  if (reader) { reader.close(); reader = null; }

  reader = new MediaMTXWebRTCReader({
    url: whepUrl,
    token,
    onError: (err) => {
      console.error("WebRTC error:", err);
      clearWatchdog();
      if (reader) { reader.close(); reader = null; }
      starting = false;

      // Stop retrying after MAX_ERROR_RETRIES to avoid flooding /whep when the
      // stream is not found. The counter resets on a successful track (onTrack)
      // or when the tab is refocused (visibilitychange).
      if (++errorRetries > MAX_ERROR_RETRIES) {
        setStatus("Stream unavailable.", true);
        showLoader(false);
        return;
      }

      setStatus("Reconnecting…");
      showLoader(true);
      retryTimer = setTimeout(() => {
        retryTimer = null;
        retryDelay = Math.min(retryDelay * 2, 30_000);
        tokenPromise = fetchToken();
        initPlayer();
      }, retryDelay);
    },
    onTrack: (evt) => {
      clearWatchdog();
      videoEl.srcObject = evt.streams[0];
      if (autoplay) {
        videoEl.play().catch((err) => {
          if (err.name === "NotAllowedError") {
            showLoader(false);
            tapOverlay.classList.remove("hidden");
          }
        });
      } else {
        showLoader(false);
        if (!controls) tapOverlay.classList.remove("hidden");
      }
      setStatus("");
      retryDelay = 2000;
      errorRetries = 0;
    },
  });

  starting = false;
}

// Reconnect immediately when tab becomes visible if stream is not active
document.addEventListener("visibilitychange", () => {
  if (document.visibilityState === "visible" && (!reader || videoEl.paused || videoEl.readyState < 2)) {
    if (retryTimer) { clearTimeout(retryTimer); retryTimer = null; }
    clearWatchdog();
    if (reader) { reader.close(); reader = null; }
    starting = false;
    errorRetries = 0;
    tokenPromise = fetchToken();
    initPlayer();
  }
});

// defer scripts execute before DOMContentLoaded, so MediaMTXWebRTCReader is ready here
window.addEventListener("DOMContentLoaded", initPlayer);
function teardown() {
  tearingDown = true;
  clearWatchdog();
  if (retryTimer) clearTimeout(retryTimer);
  if (refreshTimer) clearTimeout(refreshTimer);
  if (tokenAbort) tokenAbort.abort();
  if (reader) reader.close();
}

// pagehide fires reliably when the monitor tears an iframe down; beforeunload
// covers the standalone tab. Aborting the in-flight /api/read POST here prevents
// Apache mod_proxy_fcgi from reading a half-sent body (AH01075 → Cloudflare 520).
window.addEventListener("pagehide", teardown);
window.addEventListener("beforeunload", teardown);
</script>

</body>
</html>
