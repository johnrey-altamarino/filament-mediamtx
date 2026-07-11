# Media-over-QUIC (MoQ) Player — `/moq`

A second read-side player at `GET /moq?stream=<path>`, parallel to the WHEP `/play`. It plays a
MediaMTX stream over **Media-over-QUIC (MoQ / WebTransport)** — lower latency (~200–300 ms) via
QUIC + WebCodecs — while reusing the exact same access model as `/play` (IP gate + referrer gate +
short-lived read JWT from `/api/read`). WHIP ingest and WHEP `/play` are untouched; `/moq` is
additive and opt-in.

## How it differs from `/play`

| | WHEP (`/play`) | MoQ (`/moq`) |
|---|---|---|
| Transport | WebRTC (HTTPS signaling + UDP media) | WebTransport — a single QUIC/**UDP** connection |
| JWT delivery | `Authorization: Bearer` on WHEP HTTP | MoQ AUTH_TOKEN param inside SUBSCRIBE (WebTransport can't set headers) |
| Media path | behind Apache/Cloudflare | **direct UDP to MediaMTX** (not proxyable) |
| Cert | none (DTLS fingerprints) | real TLS cert for the hostname, loaded **into MediaMTX** |
| Browser | broad | Chrome/Edge 110+, Firefox 130+, Safari 26.4+ |

The `/moq` **page** and `/api/read` still sit behind Apache/Cloudflare, exactly as today. Only the
**media** rides a separate direct-UDP path — WebTransport is HTTP/3/QUIC and terminates inside
MediaMTX, so Apache/Nginx/Cloudflare cannot reverse-proxy it.

## Architecture

```
Browser ──HTTPS (Apache + CF, unchanged)──> Laravel: GET /moq (page) + POST /api/read (JWT)
Browser ──WebTransport / QUIC (UDP 8892, DIRECT to public IP)──> MediaMTX HTTP/3 listener (media)
```

The browser fetches a read JWT from `/api/read` (same-origin, so the referrer gate passes), then
opens a WebTransport connection to `MEDIAMTX_MOQ_BASE_URL` and passes the JWT as the MoQ AUTH_TOKEN
parameter. MediaMTX validates it via the existing JWKS setup. Video renders to a `<canvas>` via
WebCodecs; audio via Web Audio (starts muted under autoplay policy — an Unmute button appears).

## Server setup (MediaMTX)

Current prod host: **`origin.jrta.online:8892`** (public IP `122.2.44.19`), MediaMTX **v1.19.1**.

### 1. Enable the MoQ server (`mediamtx.yml`)

```yaml
moq: yes
moqHTTP2Address: :8892          # TCP/HTTP2 — MediaMTX's own page + /fingerprint (unused by our page)
moqHTTP3Address: :8892          # UDP/HTTP3 — the WebTransport media endpoint we expose
moqServerCert: /etc/letsencrypt/live/origin.jrta.online/fullchain.pem
moqServerKey:  /etc/letsencrypt/live/origin.jrta.online/privkey.pem
moqAllowOrigins: ["https://<app-origin>"]   # lock to the app origin that serves /moq
moqTrustedProxies: []
```

Reuse the existing JWT/JWKS auth (`authMethod: jwt` + `authJWTJWKS` → `/.well-known/jwks.json`) —
**no new auth** and **no `/api/read` change**; the same read JWT authorizes WHEP and MoQ.

### 2. A real TLS cert is mandatory (not `auto.crt`)

MediaMTX's default self-signed `auto.crt` (CN=mediamtx, 10-year) **does not work in browsers from a
remote host**: it's untrusted for a plain WebTransport connect, and its 10-year validity exceeds the
14-day cap browsers impose on `serverCertificateHashes` pinning — so *both* browser paths reject it.
(It only "works" on `localhost`, which browsers exempt.) Use Let's Encrypt:

```bash
# Apache owns port 80, so --standalone must stop/start it briefly (also handled on renewal).
sudo certbot certonly --standalone -d origin.jrta.online \
  --pre-hook  'systemctl stop apache2' \
  --post-hook 'systemctl start apache2' \
  --deploy-hook 'systemctl reload mediamtx'
```
(DNS-01 via the Cloudflare plugin is an alternative with zero downtime if you have a CF API token.)
The `--deploy-hook` reloads MediaMTX so the QUIC listener picks up the renewed cert. Verify:

```bash
echo | openssl s_client -connect origin.jrta.online:8892 -servername origin.jrta.online 2>/dev/null \
  | openssl x509 -noout -issuer -dates      # issuer must be Let's Encrypt, ~90-day expiry
```

### 3. Network exposure (like WebRTC's UDP media)

- **DNS-only** record `origin.jrta.online` → public IP (no Cloudflare proxy — CF can't carry WebTransport).
- Forward **UDP 8892** through the CPE NAT to the MediaMTX host (same as the existing WebRTC ICE
  UDP forward, just port 8892). Confirm it's **UDP**.
- `ufw` must allow `8892/udp`.
- Trade-off: this path exposes the origin IP for `origin.jrta.online`. Only UDP 8892 is open there;
  the app + WHEP stay behind Cloudflare.

Sanity checks on the server:
```bash
sudo ss -ulnp | grep 8892          # expect a udp listener owned by mediamtx
sudo ufw status verbose | grep 8892
curl -s http://127.0.0.1:9997/v3/paths/list | jq '.items[] | {name, ready, tracks}'  # H264 + Opus
```

## App setup

Set the endpoint in `.env`:
```
MEDIAMTX_MOQ_BASE_URL=https://origin.jrta.online:8892
```
Then `php artisan config:clear` and `npm run build`. The `/moq` route reuses the `player.access`
middleware and the `allow_direct_playback` toggle — same as `/play`. Open `/moq?stream=<path>`
(add `?stream=testcg1`, etc.).

## Verify

1. Publish an H.264 + Opus stream via WHIP (WebRTC ingest).
2. Open `/moq?stream=<path>` in **Chrome**. Expect video on the canvas within a second or two, and
   an Unmute button for audio.
3. Compare latency against `/play`.
4. On an unsupported browser (e.g. old Safari) confirm the fallback message links to `/play`.

## Troubleshooting

- **"Opening handshake failed", browser never plays** — the QUIC/UDP handshake didn't complete.
  Capture on the server: `sudo tcpdump -ni any udp port 8892` while connecting.
  - *No inbound packets* → CPE isn't forwarding UDP 8892 (or CG-NAT). Fix the forward.
  - *Bidirectional packets but the client keeps re-sending its Initial* → the server's replies aren't
    reaching the client. Usually the **client network** (corporate Wi-Fi filtering QUIC on non-443
    ports) or a **5G-uplink MTU** blackholing the ~1280-byte server reply. Test from a different
    network (phone hotspot). This is inherent to WebTransport; affected viewers should use `/play`.
- **Immediate cert error** — MediaMTX is serving `auto.crt`, or the cert isn't for `origin.jrta.online`,
  or MediaMTX wasn't restarted after the cert change. Re-run the `openssl` check above.
- **"Access denied (403/401)" shown, player stops** — `/api/read` refused to issue a token: the
  viewer's IP is on `blocked_ips`, the `Referer` isn't allow-listed, or `allow_direct_playback` is
  off for a non-embedded load. This is a *terminal* state by design (the page no longer retries).
- **Auth rejected (MoQ REQUEST_ERROR)** — a token *was* issued but MediaMTX rejected the MoQ
  subscribe: the JWT path/permission doesn't match, or JWKS auth isn't configured on the MoQ
  listener. Confirm `authMethod: jwt` applies and the token's `path` matches.

## Parity with `/play`

`/moq` intentionally differs from `/play` on a few player options, because MoQ renders to a
`<canvas>` (not a `<video>`) and audio is governed by the browser autoplay policy:

- `autoplay` and `controls` query params **do not apply** and are ignored.
- Audio always **starts muted/suspended**; an **Unmute** button appears. `?muted=false` is honoured
  only as a *best-effort* auto-unmute after subscribe — the browser may keep audio suspended until
  the viewer clicks Unmute.

Everything else (the `player.access` IP gate, `allow_direct_playback`, iframe/embed rules, the
`/api/read` JWT flow, `?stream=`) behaves exactly as `/play`.

## Reader fork & player hardening

`resources/js/moq-reader.js` is MediaMTX's own `MediaMTXMoQReader` (v1.19.1, `moqt-18`) vendored with
a small, documented set of fork edits (listed in the file header). Beyond the cert simplifications
(no `serverCertificateHashes`/fingerprint, trusted LE cert), a code review drove these robustness
additions:

- **`stop()` (new public method)** — upstream only auto-retries and has no stop path. The player
  calls `reader.stop()` on `pagehide`/`beforeunload` so the WebTransport connection and the ~2s
  restart loop are torn down instead of leaking when the tab is closed or backgrounded (bfcache).
- **Terminal-error handling** — a token-provider error flagged `terminal` (a permanent gate denial:
  a 4xx from `/api/read` other than `429`) routes to `stop()` + a fatal message, **instead of
  retrying `/api/read` every ~2s forever**. A blocked IP / disallowed referrer now sees a clear
  "Access denied (4xx)" and the client stops, rather than spinning on "Reconnecting…".
- **`onError(msg, terminal)`** — the callback now receives a `terminal` boolean. The page shows a
  generic "Reconnecting…" for transient (auto-retried) errors and the actual reason only for
  terminal ones — viewers no longer see raw QUIC/MoQ strings on a blip.
- **Config guard** — an empty `MEDIAMTX_MOQ_BASE_URL` shows "Media-over-QUIC is not configured"
  rather than constructing an invalid relative WebTransport URL.

Transient failures (network blips, stream restarts) still auto-retry via the reader's built-in loop.

## Known-good vs. to-confirm

Validated on prod during the spike: server listeners, ufw, UDP forward, Let's Encrypt cert, MediaMTX
v1.19.1 with `moqt-18`, stream is H.264+Opus, and a real bidirectional QUIC handshake. The exact
**WebTransport URL path shape** (`<base>/<stream>/moq`) and **JWT-over-MoQ auth** were not fully
confirmed end-to-end (the test client's network blocked handshake completion) — both are one-line
adjustments in `moq-player.blade.php` / `moq-reader.js` if the live deploy shows otherwise.
