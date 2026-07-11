<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Media-over-QUIC Player</title>
@vite('resources/js/moq-reader.js')
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
  #video {
    width: 100%;
    height: 100%;
    position: relative;
  }
  #video canvas {
    position: absolute;
    inset: 0;
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
  #status.error { color: #ff6b6b; }
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
  #mute {
    position: absolute;
    bottom: 20px;
    right: 20px;
    padding: 10px 14px;
    border-radius: 24px;
    background: rgba(0,0,0,0.55);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.25);
    font-family: sans-serif;
    font-size: 0.8rem;
    cursor: pointer;
    display: none;
  }
  #mute.visible { display: block; }
  #unsupported {
    color: #fff;
    font-family: sans-serif;
    text-align: center;
    padding: 2rem;
    max-width: 32rem;
    line-height: 1.5;
  }
  #unsupported a { color: #9ec5ff; }
  .hidden { display: none; }
</style>
</head>
<body>

<div id="video"></div>
<div id="loader">
  <div id="spinner" class="spinner"></div>
  <div id="status"></div>
</div>
<button id="mute" type="button">🔇 Unmute</button>
<div id="unsupported" class="hidden"></div>

<script>
const MOQ_BASE_URL = "{{ $moqBaseUrl }}";
const EMBED_SOURCE = @json($embedSource);

const statusEl = document.getElementById("status");
const spinnerEl = document.getElementById("spinner");
const muteEl = document.getElementById("mute");
const videoEl = document.getElementById("video");
const unsupportedEl = document.getElementById("unsupported");

const params = new URLSearchParams(window.location.search);
const stream = params.get("stream");
// NOTE ON PARITY WITH /play: MoQ renders to a <canvas>, not a <video>, so the
// `autoplay` and `controls` params do not apply. Audio always starts suspended
// under the browser autoplay policy; `muted=false` is honoured only as a
// best-effort auto-unmute after subscribe (browsers may keep it suspended until
// the viewer clicks Unmute).
const startUnmuted = params.get("muted") === "false";

function setStatus(msg, isError = false) {
  statusEl.textContent = msg;
  statusEl.classList.toggle("error", isError);
}
function showLoader(v) { spinnerEl.classList.toggle("hidden", !v); }

// Render a terminal, non-retryable message (unsupported browser, misconfig, access denied).
function showFatal(html) {
  document.getElementById("loader").classList.add("hidden");
  unsupportedEl.classList.remove("hidden");
  unsupportedEl.innerHTML = html;
}

if (!stream) {
  document.body.innerHTML = '<p style="color:#fff;font-family:sans-serif;text-align:center;margin-top:2rem">Missing required <code>?stream=</code> parameter.</p>';
  throw new Error("Missing stream parameter");
}

// MoQ playback needs WebTransport + WebCodecs. Fall back to the WHEP player otherwise.
if (typeof window.WebTransport === "undefined" || typeof window.VideoDecoder === "undefined") {
  const fallbackHref = `/play${window.location.search}`;
  showFatal(
    "Your browser doesn't support Media-over-QUIC playback. " +
    "Use Chrome/Edge 110+, Firefox 130+, or Safari 26.4+, or " +
    '<a href="' + fallbackHref + '">open the standard player</a>.'
  );
  throw new Error("WebTransport/WebCodecs unsupported");
}

// Without a configured endpoint the WebTransport URL would be an invalid relative
// path; fail clearly instead of surfacing a raw browser error.
if (!MOQ_BASE_URL) {
  showFatal("Media-over-QUIC is not configured (MEDIAMTX_MOQ_BASE_URL is unset).");
  throw new Error("MOQ_BASE_URL not configured");
}

let tokenAbort = null;
let tearingDown = false;

// Fetch a fresh read JWT. The reader calls this via `tokenProvider` before each (re)connect,
// so a stream that reconnects after the short JWT TTL still authorizes. Mirrors /play's gate:
// the POST is same-origin, so the referrer gate in /api/read passes.
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
      if (res.status >= 500 && attempt < 2) continue;
      if (!res.ok) {
        const err = new Error(`Access denied (${res.status})`);
        // 4xx (except 429 rate-limit) is a permanent denial from the gate: mark
        // terminal so the reader stops instead of re-POSTing /api/read forever.
        err.terminal = res.status >= 400 && res.status < 500 && res.status !== 429;
        throw err;
      }
      const data = await res.json();
      if (!data.token) throw new Error("JWT not received");
      return data.token;
    } catch (err) {
      if (err.name === "AbortError" || err.terminal) throw err;
      if (attempt === 2) throw err;
    }
  }
  throw new Error("Failed to obtain token");
}

let reader = null;

function startPlayer() {
  setStatus("Connecting…");
  showLoader(true);

  reader = new MediaMTXMoQReader({
    // NOTE: exact MoQ URL/path shape confirmed on deploy; default is <base>/<stream>/moq.
    url: `${MOQ_BASE_URL}/${stream}/moq`,
    // MediaMTX's HTTP/3 listener serves a self-signed cert that the reader pins via
    // serverCertificateHashes; its fingerprint is published here (served over the trusted
    // LE cert on the HTTP/2 listener, so this cross-origin fetch validates).
    fingerprintUrl: `${MOQ_BASE_URL}/fingerprint`,
    tokenProvider: fetchToken,
    videoElement: videoEl,
    onError: (msg, terminal) => {
      if (terminal) {
        // The reader has stopped (e.g. access denied); show the reason, no spinner.
        showFatal(msg);
      } else {
        // Transient failure — the reader auto-retries; keep the viewer-facing copy generic.
        setStatus("Reconnecting…", true);
        showLoader(true);
      }
    },
    onSubscribed: () => {
      setStatus("");
      showLoader(false);
      if (startUnmuted && reader) reader.unmute(); // best-effort; autoplay policy may keep it muted
    },
    onAudioMuted: (isMuted) => { muteEl.classList.toggle("visible", isMuted); },
  });
}

muteEl.addEventListener("click", () => { if (reader) reader.unmute(); });

function teardown() {
  tearingDown = true;
  if (tokenAbort) tokenAbort.abort();
  if (reader) reader.stop(); // close the WebTransport + cancel the retry loop (upstream reader had no stop path)
}
window.addEventListener("pagehide", teardown);
window.addEventListener("beforeunload", teardown);

window.addEventListener("DOMContentLoaded", startPlayer);
</script>

</body>
</html>
