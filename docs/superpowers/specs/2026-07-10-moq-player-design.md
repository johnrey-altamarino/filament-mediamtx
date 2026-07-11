# MoQ (Media-over-QUIC) Player — Design Spec
**Date:** 2026-07-10
**Branch:** develop

---

## Overview

Add a second read-side player, at `GET /moq?stream=<path>`, that plays MediaMTX streams over
**Media-over-QUIC (MoQ / WebTransport)** instead of WebRTC/WHEP. The existing WHIP ingest and
WHEP player (`/play`) are left untouched — `/moq` is a parallel, opt-in playback path offering
lower latency (~200–300 ms) via QUIC + WebCodecs.

The page mirrors the existing `/play` security and UX model exactly: same IP gate + referrer gate,
the same short-lived read JWT from `POST /api/read`, the same styling/loader/error behavior. The
only new moving parts are (1) a MoQ-capable MediaMTX server exposed over UDP, and (2) a vendored
copy of MediaMTX's own browser MoQ reader.

### Why a new player instead of extending `/play`

WHEP and MoQ are different transports with incompatible browser stacks:

| | WHEP (`/play`) | MoQ (`/moq`) |
|---|---|---|
| Transport | WebRTC (HTTPS signaling + UDP media) | WebTransport (single QUIC/UDP connection) |
| Render | `<video>` + `MediaMTXWebRTCReader` | `<canvas>` (WebGL2/2D) + WebCodecs + Web Audio |
| JWT delivery | `Authorization: Bearer` header on WHEP HTTP | MoQ AUTH_TOKEN param inside SUBSCRIBE (no header possible over WebTransport) |
| Cert | none (DTLS fingerprints) | real TLS cert for the hostname, in MediaMTX |
| Browser support | broad | Chrome/Edge 110+, Firefox 130+, Safari 26.4+ |

They cannot share a reader or a `<video>` element, so `/moq` is its own page.

---

## Architecture / Topology

The app page + token issuance stay behind Apache/Cloudflare exactly as today. **Only the MoQ media
rides a separate, direct UDP path** — WebTransport is HTTP/3/QUIC and terminates *inside MediaMTX*;
it cannot be reverse-proxied by Apache/Nginx or Cloudflare.

```
Browser ──HTTPS (Apache + CF, unchanged)──> Laravel: GET /moq (page) + POST /api/read (JWT)
Browser ──WebTransport / QUIC (UDP 8892, DIRECT to public IP)──> MediaMTX moqHTTP3Address (media)
```

Mental model: **same shape as the existing WebRTC UDP media path.** Requirements:

1. Public IP reachable (already true — WebRTC ICE uses it).
2. UDP port (default 8892) forwarded to the MediaMTX host.
3. DNS-only (grey-cloud) record `origin.jrta.online` → public IP.
4. **New vs. WebRTC:** a valid TLS cert for `origin.jrta.online` loaded *into MediaMTX* (WebRTC needs
   no domain cert; WebTransport validates the cert against the hostname, so the URL must use the
   hostname, not a bare IP).

Only **UDP** needs forwarding — not TCP. The page is served by the Laravel app and the vendored
reader is forked to drop the `/fingerprint` fetch (see Section 4), so nothing hits MediaMTX's
TCP/HTTP2 MoQ listener.

**Trust-model note:** this UDP path exposes the origin public IP for `origin.jrta.online` (no
Cloudflare proxy in front, since CF can't proxy WebTransport). Accepted. Only UDP 8892 is open
there; the app + WHEP stay behind Cloudflare.

---

## Section 1 — MediaMTX Server Config (`mediamtx.yml`)

Enable the MoQ server and load the real cert. Reuse the **existing JWT/JWKS auth** already
configured for WHEP (`authMethod: jwt` + `authJWTJWKS` → `/.well-known/jwks.json`). No new auth.

```yaml
moq: yes
moqHTTP2Address: :8892          # TCP/HTTP2 — page + /fingerprint (unused by our page; keep default)
moqHTTP3Address: :8892          # UDP/HTTP3 — WebTransport media endpoint (this is what we expose)
moqServerCert: /etc/letsencrypt/live/origin.jrta.online/fullchain.pem
moqServerKey:  /etc/letsencrypt/live/origin.jrta.online/privkey.pem
moqAllowOrigins: ["https://<app-origin>"]   # lock to the app origin that serves /moq
moqTrustedProxies: []
```

- Do **not** use `auto.key`/`auto.crt` — browsers must validate a real cert for the hostname (we
  are deliberately not using the self-signed + fingerprint-pinning path).
- `moqAllowOrigins` restricts CORS to the app origin (defense in depth; the JWT gate is the real
  control).

### TLS cert (certbot)

- Issue `origin.jrta.online` via certbot (DNS-01 through Cloudflare, since the record is DNS-only).
- `--deploy-hook` reloads MediaMTX on renewal so the QUIC listener picks up the rotated cert.
- MediaMTX must have read access to the cert files (permissions/ownership on the deploy host).

### Infra checklist (server-side, outside this repo)

- [ ] DNS-only `origin.jrta.online` → public IP.
- [ ] UDP 8892 forwarded through the 5G CPE NAT to the MediaMTX host.
- [ ] certbot cert issued + deploy-hook reload wired.
- [ ] `moq: yes` and the cert paths set in `mediamtx.yml`; MediaMTX restarted.

---

## Section 2 — Route & Controller

### Route

Add to `routes/web.php`, using the **same** `player.access` IP gate as `/play`:

```php
Route::get('/moq', MoqPlayerController::class)->middleware('player.access');
```

### Controller

`app/Http/Controllers/MoqPlayerController.php` — a near-exact copy of `PlayerController` (same
`allow_direct_playback` check, same iframe/embed-source detection, same 403 rule), differing only
in the view name and the base-URL config key:

```php
return view('moq-player', [
    'moqBaseUrl'      => (string) config('services.mediamtx.moq_base_url', ''),
    'tokenEndpoint'   => url('/api/read'),
    'playbackEnabled' => true,
    'embedSource'     => $embedSource,
]);
```

The `originOf()` helper and embed logic are identical to `PlayerController`. (Both controllers now
share this logic — if it drifts, extract a small trait/base later; not required for this change.)

**`/api/read` is unchanged.** The same read JWT (`mediamtx_permissions: [{action: read, path}]`)
authorizes both WHEP and MoQ. The referrer gate still works because the token is fetched from the
`/moq` page's own origin, exactly as `/play` does.

---

## Section 3 — Config

Add to `config/services.php` under the `mediamtx` block:

```php
'moq_base_url' => env('MEDIAMTX_MOQ_BASE_URL', ''),
```

Add to `.env.example`:

```
# Media-over-QUIC (MoQ) WebTransport endpoint (hostname + UDP port; must use hostname for TLS)
MEDIAMTX_MOQ_BASE_URL=https://origin.jrta.online:8892
```

---

## Section 4 — Vendored MoQ Reader

Vendor MediaMTX's own browser reader (`MediaMTXMoQReader`, the ~1k-line class from the MediaMTX
repo) into `resources/js/moq-reader.js`. Treat it as a vendored dependency (header comment noting
upstream source + version so future syncs are traceable). Wire it exactly like `reader.js`: add
`resources/js/moq-reader.js` to the Vite `input` array in `vite.config.js` and load it in the blade
via `@vite('resources/js/moq-reader.js')`. The class self-registers as `window.MediaMTXMoQReader`
(kept from upstream), so the blade uses it as a global — same pattern as `MediaMTXWebRTCReader`.

### Fork: two deletions only

Because we use a **real, trusted** cert (not self-signed), the fingerprint/pinning path is
unnecessary and would otherwise force a cross-origin fetch to `:8892`:

1. In `#connect()`, remove the `serverCertificateHashes` option — connect with a plain
   `new WebTransport(this.#conf.url, { protocols: [MOQT_VERSION] })`. The browser validates the
   real cert against the hostname.
2. In `#start()`, drop the `#fetchFingerprint()` step (and the `fingerprintUrl` config / the
   `#authHeader()`-based fetch it performs). The JWT still reaches the server via
   `#encodeAuthParams()` (the MoQ AUTH_TOKEN param in SUBSCRIBE) — that path is untouched.

Everything else (WebCodecs decode, WebGL2/2D canvas render, reorderer, audio + Web Audio, restart
loop) stays byte-for-byte from upstream to keep future updates cheap.

### Auth

Pass the JWT as `conf.token`. The reader wraps it as `Bearer <jwt>` inside the MoQ AUTH_TOKEN
parameter of each SUBSCRIBE — MediaMTX validates it via JWKS. No query param, no HTTP header.

---

## Section 5 — Player View (`resources/views/moq-player.blade.php`)

Model on `resources/views/player.blade.php` but adapt the parts that differ between WebRTC and MoQ.

### Same as `/play`

- Same page shell, dark background, centered loader/spinner, `#status` text, "tap to play" affordance.
- Same URL params: `stream` (required — same missing-param guard), `autoplay`, `muted`, `controls`.
- Same token flow against `{{ $tokenEndpoint }}`: POST `{ path: stream, source: EMBED_SOURCE }` with
  `credentials: "include"`, same 3-attempt retry, same auth-denial handling, same `pagehide`/
  `beforeunload` teardown that aborts the in-flight POST.

### Different from `/play`

- **Render target:** the reader appends a `<canvas>` into a container element (`videoElement`
  config), not a `<video>`. Markup uses a `<div id="video">` container styled like the current
  `<video>` (full-bleed, `object-fit: contain`).
- **Audio / mute:** MoQ audio uses Web Audio and starts suspended under autoplay policy. Wire the
  reader's `onAudioMuted(muted)` callback to a mute/unmute button (reuse the reader's `unmute()`),
  mirroring MediaMTX's native page. When `muted !== false` (default), leave audio suspended and show
  the unmute control.
- **Reader instantiation** instead of `MediaMTXWebRTCReader`:

  ```js
  reader = new MediaMTXMoQReader({
    url: `${MOQ_BASE_URL}/${stream}`,   // hostname:port, path = stream
    token,                              // JWT from /api/read
    videoElement: document.getElementById("video"),
    onError:     (msg) => { /* status + existing retry/backoff */ },
    onSubscribed:(hasAudio) => { /* clear loader/status; show mute if audio */ },
    onAudioMuted:(muted) => { /* toggle mute button */ },
  });
  ```

  (Exact MoQ URL/path shape — trailing slash, how the stream path maps onto the WebTransport URL —
  is confirmed in the spike; adjust `url` accordingly.)

- **Token refresh / reconnect (see Section 6):** feed fresh tokens into the reader rather than
  relying on the value captured at construction.

- **Browser-support guard:** if `window.WebTransport` or `window.VideoDecoder` is undefined, do not
  instantiate the reader. Show a friendly message ("Your browser doesn't support Media-over-QUIC")
  with a link to the WHEP fallback: `/play?stream=<same params>`.

---

## Section 6 — Read-Token Lifetime for MoQ

The vendored reader captures `token` once at construction and reuses it on every internal restart.
The current read JWT TTL is 60s (`JWT_TTL_SECONDS`), so a reconnect after 60s would present a stale
token and fail auth.

**Decision (finalize in the plan):** add a token-provider hook to the vendored reader so each
`#start()`/reconnect pulls a fresh token from the page's existing `fetchToken()` refresh loop
(preferred — keeps TTL short and reuses `/play`'s proven refresh logic). A minimal alternative is a
longer MoQ-specific TTL, but that weakens the short-lived-token property, so the token-provider hook
is the intended approach. Either way, `/api/read` and the WHEP flow are unaffected.

---

## Section 7 — Out of Scope (YAGNI)

- Multistream **monitor** stays on WHEP — no `/monitor/moq` in this pass.
- **WHIP ingest** and **WHEP `/play`** are untouched.
- No MoQ **publishing** — read-side only.
- No Filament UI changes (no new resource/page/nav). Admins reach `/moq?stream=<path>` directly,
  same as `/play`. (A nav link can be added later if desired.)

---

## Section 8 — Testing / Verification

### Step 0 — Local spike (first implementation step, de-risks everything)

Before touching prod infra, run MediaMTX locally with `moq: yes` on `localhost:8892` (WebTransport
works on localhost without cert hassle) and prove end-to-end:

- [ ] A WHIP-published H.264+Opus test stream plays in `/moq` in Chrome.
- [ ] The `/api/read` JWT authorizes the MoQ subscribe (AUTH_TOKEN param path).
- [ ] Confirm the exact MoQ URL/path shape and any `moq*` config-key/default-port specifics for the
      installed MediaMTX version.
- [ ] Confirm reconnect-after-token-expiry behavior and validate the token-provider hook.

The spike's results feed back into Sections 4–6 before the rest is built.

### Automated tests

- `MoqPlayerController` gate behavior mirroring existing `PlayerController` tests: 403 when direct
  playback disabled and not embedded; view renders with the correct `moqBaseUrl`; embed-source
  detection for the iframe case.

### Manual E2E (once infra is up)

- Publish via WHIP → open `/moq?stream=<path>` on `origin.jrta.online` in a supported browser →
  confirm playback, audio unmute, reconnect, and compare latency vs WHEP.
- Confirm the unsupported-browser guard shows the WHEP fallback (e.g. old Safari).

---

## Section 9 — Documentation (per CLAUDE.md)

- `docs/` guide (Symptom/goal → server config + cert → DNS/UDP exposure → player → verify → deploy).
- `README.md`: new `/moq` route, new `MEDIAMTX_MOQ_BASE_URL` env var, MoQ topology note (direct UDP,
  not proxyable), and the server/infra deploy steps. Keep the README table-of-contents in sync.

---

## Files Created / Modified

| Action | Path |
|---|---|
| Create | `app/Http/Controllers/MoqPlayerController.php` |
| Create | `resources/views/moq-player.blade.php` |
| Create | `resources/js/moq-reader.js` (vendored `MediaMTXMoQReader`, forked: no fingerprint/pinning) |
| Create | `tests/Feature/MoqPlayerControllerTest.php` |
| Create | `docs/<moq-player-guide>.md` |
| Modify | `routes/web.php` |
| Modify | `config/services.php` |
| Modify | `.env.example` |
| Modify | `README.md` |
| Modify | `vite.config.js` — add `resources/js/moq-reader.js` to the `laravel({ input: [...] })` array (same as `reader.js`) |
| Server (outside repo) | `mediamtx.yml` (`moq: yes`, cert paths, `moqAllowOrigins`); certbot + deploy-hook; DNS-only record; UDP 8892 forward |

---

## Open Items Resolved in the Spike (not blockers)

1. Exact MoQ WebTransport URL/path shape for the installed MediaMTX version (trailing slash / how
   the stream name maps onto the URL).
2. Precise `moq*` config keys / default port for the installed version (config block already
   provided by the user; confirm against the running binary).
3. Final token-refresh mechanism (Section 6) validated against real reconnect timing.
