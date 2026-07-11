# MoQ (Media-over-QUIC) Player Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a MoQ read player at `GET /moq?stream=<path>`, parallel to the existing WHEP `/play`, reusing the `/api/read` JWT gate and MediaMTX's own vendored browser MoQ reader.

**Architecture:** A thin Blade page (`/moq`) modeled on `player.blade.php` issues the same short-lived read JWT from `/api/read` (same IP + referrer gate), then plays via a vendored copy of MediaMTX's `MediaMTXMoQReader` over WebTransport. The MoQ media rides a **direct UDP** path to MediaMTX (WebTransport = HTTP/3/QUIC, not reverse-proxyable); the app page + token issuance stay behind Apache/Cloudflare unchanged.

**Tech Stack:** Laravel 12 / PHP 8.2, Blade, Vite, vanilla JS (WebTransport + WebCodecs), MediaMTX MoQ server, certbot.

## Global Constraints

- Read JWT is unchanged: `mediamtx_permissions: [{action: read, path}]`, RS256, verified by MediaMTX via JWKS. **No changes to `/api/read` or `MediamtxJwtIssuer`.**
- WHIP ingest, WHEP `/play`, and the multistream monitor are **untouched**.
- The `/moq` page reuses the existing `player.access` IP-gate middleware and the `allow_direct_playback` / embed rules from `PlayerController` verbatim.
- MoQ media is exposed over **UDP only** (default port 8892), direct to the public IP via a DNS-only `moq.jrta.online` record. The cert lives **inside MediaMTX** (`moqServerCert`/`moqServerKey`), never in Apache.
- The connection URL uses the **hostname** (`https://moq.jrta.online:8892/...`), never a bare IP (TLS validates against the hostname).
- Vendored reader stays as close to upstream `MediaMTXMoQReader` as possible — only the three edits in Task 3 are permitted, so future upstream syncs stay cheap.
- Tests that hit `/moq` use `RefreshDatabase` + `withoutVite()` (follow `MonitorPlayerControllerTest`, not the isolation-broken `PlayerControllerTest`).
- Spec: `docs/superpowers/specs/2026-07-10-moq-player-design.md`.

---

## File Structure

| File | Responsibility |
|---|---|
| `resources/js/moq-reader.js` | Vendored `MediaMTXMoQReader`, forked: no fingerprint fetch, no `serverCertificateHashes`, adds a `tokenProvider` refresh hook. |
| `resources/views/moq-player.blade.php` | The `/moq` page: token flow (reuses `/play`'s logic), canvas render target, mute button, browser-support guard → WHEP fallback. |
| `app/Http/Controllers/MoqPlayerController.php` | Gate + view for `/moq`; near-exact copy of `PlayerController` (view name + config key differ). |
| `config/services.php` | Add `mediamtx.moq_base_url`. |
| `.env.example` | Add `MEDIAMTX_MOQ_BASE_URL`. |
| `vite.config.js` | Add `resources/js/moq-reader.js` to Vite inputs. |
| `routes/web.php` | Add `GET /moq` with `player.access`. |
| `tests/Feature/MoqPlayerControllerTest.php` | Gate + config-injection tests. |
| `docs/moq-player.md` | Ops/deploy guide (server config, cert, DNS/UDP, verify). |
| `README.md` | New route, env var, MoQ topology + deploy steps. |
| `mediamtx.yml` (server, outside repo) | `moq: yes`, cert paths, `moqAllowOrigins`. |

---

## Task 1: Local Spike — validate MoQ playback + JWT end-to-end

**Goal:** De-risk every downstream assumption before writing app code. This task produces **findings**, recorded back into this plan, not shippable code. No commit of app code; the only artifact is a short findings note appended to the plan file.

**Files:**
- Scratch only (a throwaway HTML file + a local `mediamtx.yml`). Nothing committed except the findings note at the end.

- [ ] **Step 1: Run MediaMTX locally with MoQ + JWT auth**

Enable MoQ and point JWT auth at this app's JWKS in a local `mediamtx.yml`:

```yaml
moq: yes
moqHTTP2Address: :8892
moqHTTP3Address: :8892
# auto self-signed is fine ON LOCALHOST (WebTransport allows localhost w/o trusted cert)
moqServerKey: auto.key
moqServerCert: auto.crt
moqAllowOrigins: ["*"]

authMethod: jwt
authJWTJWKS: http://<this-app-host>/.well-known/jwks.json
```

Start the app (`composer run dev`) and MediaMTX. Publish an H.264+Opus test stream via WHIP (same encoder path you use today), path e.g. `live/spike`.

- [ ] **Step 2: Mint a read JWT and try the reader in a scratch page**

Get a token from the running app (temporarily relax the referrer gate or call `MediamtxJwtIssuer::issueReadToken('live/spike')` via `php artisan tinker`). Drop `reader.js` (the `MediaMTXMoQReader` the user provided) into a scratch HTML page and instantiate it against localhost, passing `token`. Record answers to:

- **URL/path shape:** what exact WebTransport `url` makes MediaMTX subscribe to `live/spike`? (Native page uses `new URL("moq", location) + location.search`; confirm whether it's `https://host:8892/<path>/moq`, `https://host:8892/<path>`, or query-based.)
- **MOQT version:** does the vendored `#MOQT_VERSION = "moqt-18"` match this MediaMTX build? (A mismatch fails at SETUP.)
- **Auth:** does passing `token` (→ `Bearer` in the AUTH_TOKEN param) authorize the subscribe? Confirm a bad/expired token is rejected.
- **Cert path with a real cert:** confirm that with a *trusted* cert (later, prod) we can connect with **no** `serverCertificateHashes` and **no** `/fingerprint` fetch. On localhost, also confirm dropping `serverCertificateHashes` works (localhost is exempt from the cert requirement).
- **Reconnect/token expiry:** let the 60s token expire, force a reconnect (kill/restart the stream), confirm whether a stale token fails — validating the need for the Task 3 `tokenProvider` hook.

- [ ] **Step 3: Record findings in this plan**

Append a `## Spike Findings (Task 1)` section to this file with the confirmed values for: WebTransport URL template, MOQT version, auth confirmation, cert handling, token-refresh behavior. Downstream tasks reference these. Commit **only** this doc change:

```bash
git add docs/superpowers/plans/2026-07-10-moq-player.md
git commit -m "docs: record MoQ spike findings"
```

**If the spike fails** (e.g. MOQT version mismatch with no compatible reader, or auth can't be passed): stop and revise the spec before continuing. Do not proceed to Task 2.

---

## Task 2: Config, Controller & Route

**Files:**
- Modify: `config/services.php` (add `mediamtx.moq_base_url`)
- Modify: `.env.example`
- Create: `app/Http/Controllers/MoqPlayerController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/MoqPlayerControllerTest.php`

**Interfaces:**
- Consumes: `config('services.mediamtx.moq_base_url')`, the `player.access` middleware alias, `App\Models\PlayerSetting`.
- Produces: route `GET /moq` → view `moq-player` with view data `moqBaseUrl` (string), `tokenEndpoint` (string url), `playbackEnabled` (bool true), `embedSource` (string).

- [ ] **Step 1: Add the config key**

In `config/services.php`, inside the `'mediamtx' => [ ... ]` array (next to `stream_base_url`), add:

```php
'moq_base_url' => env('MEDIAMTX_MOQ_BASE_URL', ''),
```

- [ ] **Step 2: Add the env example line**

In `.env.example`, near `MEDIAMTX_STREAM_BASE_URL=`, add:

```
# Media-over-QUIC (MoQ) WebTransport endpoint (hostname + UDP port; must use hostname for TLS)
MEDIAMTX_MOQ_BASE_URL=https://moq.jrta.online:8892
```

- [ ] **Step 3: Write the failing controller tests**

Create `tests/Feature/MoqPlayerControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Middleware\PlayerAccessMiddleware;
use App\Models\PlayerSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoqPlayerControllerTest extends TestCase
{
    use RefreshDatabase;

    private function allowDirect(): void
    {
        // Firing the model event busts the cached 'player_setting:allow_direct_playback'.
        PlayerSetting::create(['key' => 'allow_direct_playback', 'value' => '1']);
    }

    public function test_moq_route_403_when_direct_disabled_and_not_embedded(): void
    {
        $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->withoutVite()
            ->get('/moq?stream=live/a')
            ->assertStatus(403);
    }

    public function test_moq_route_returns_200_when_direct_allowed(): void
    {
        $this->allowDirect();

        $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->withoutVite()
            ->get('/moq?stream=live/a')
            ->assertStatus(200);
    }

    public function test_moq_route_injects_moq_base_url(): void
    {
        $this->allowDirect();
        config(['services.mediamtx.moq_base_url' => 'https://moq.example.test:8892']);

        $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->withoutVite()
            ->get('/moq?stream=live/a')
            ->assertStatus(200)
            ->assertSee('https://moq.example.test:8892', escape: false);
    }

    public function test_moq_route_injects_token_endpoint(): void
    {
        $this->allowDirect();

        $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->withoutVite()
            ->get('/moq?stream=live/a')
            ->assertStatus(200)
            ->assertSee('/api/read', escape: false);
    }
}
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `php artisan test tests/Feature/MoqPlayerControllerTest.php`
Expected: FAIL — route `/moq` not defined (404) / controller + view missing.

- [ ] **Step 5: Create the controller**

Create `app/Http/Controllers/MoqPlayerController.php` (mirrors `PlayerController` exactly; only the view name and config key differ):

```php
<?php

namespace App\Http\Controllers;

use App\Models\PlayerSetting;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MoqPlayerController extends Controller
{
    public function __invoke(Request $request): View
    {
        $isEmbed = $request->headers->get('sec-fetch-dest') === 'iframe';

        $directAllowed = (bool) cache()->remember(
            'player_setting:allow_direct_playback',
            30,
            fn () => PlayerSetting::get('allow_direct_playback', false)
        );

        // When embedded, the embedding site's origin is the real "source" of the view.
        $embedSource = '';
        if ($isEmbed) {
            $origin = (string) $request->headers->get('origin', '');
            $referer = (string) $request->headers->get('referer', '');
            $embedSource = $this->originOf(($origin !== '' && $origin !== 'null') ? $origin : $referer);
        }

        if (! $isEmbed && ! $directAllowed) {
            abort(403);
        }

        return view('moq-player', [
            'moqBaseUrl' => (string) config('services.mediamtx.moq_base_url', ''),
            'tokenEndpoint' => url('/api/read'),
            'playbackEnabled' => true,
            'embedSource' => $embedSource,
        ]);
    }

    /**
     * Reduce a URL to its origin (scheme://host[:port]), lowercased; '' if unparseable.
     */
    private function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $origin = strtolower($parts['scheme']).'://'.strtolower($parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
```

- [ ] **Step 6: Register the route**

In `routes/web.php`, add the import and route next to the existing `/play` route:

```php
use App\Http\Controllers\MoqPlayerController;
```

```php
Route::get('/moq', MoqPlayerController::class)->middleware('player.access');
```

**Note:** the tests will still fail until the `moq-player` view exists (Task 4). That's expected — Step 7 confirms the controller/route/config wiring compiles and the 403 path works without the view.

- [ ] **Step 7: Run the 403 test to verify controller wiring**

Run: `php artisan test tests/Feature/MoqPlayerControllerTest.php --filter test_moq_route_403_when_direct_disabled_and_not_embedded`
Expected: PASS (the 403 fires before the view renders). The three 200-asserting tests remain failing until Task 4.

- [ ] **Step 8: Commit**

```bash
git add config/services.php .env.example app/Http/Controllers/MoqPlayerController.php routes/web.php tests/Feature/MoqPlayerControllerTest.php
git commit -m "feat: add /moq route, controller, and config (view pending)"
```

---

## Task 3: Vendored MoQ reader (forked)

**Files:**
- Create: `resources/js/moq-reader.js`
- Modify: `vite.config.js`

**Interfaces:**
- Produces: global `window.MediaMTXMoQReader`, constructed with `{ url, token, tokenProvider, videoElement, onError, onSubscribed, onAudioMuted }`. `tokenProvider` is an optional `async () => string` called before each (re)connect; when present its result replaces `token`.

- [ ] **Step 1: Vendor the upstream reader verbatim**

Copy the full `MediaMTXMoQReader` class (the `reader.js` the user provided from the MediaMTX repo) into `resources/js/moq-reader.js` **unchanged**, including the trailing `window.MediaMTXMoQReader = MediaMTXMoQReader;`. Add a header comment:

```js
// Vendored from MediaMTX (bluenviron/mediamtx) — MoQ browser reader (MediaMTXMoQReader).
// Forked edits (this repo): drop fingerprint fetch + serverCertificateHashes (we use a
// real trusted cert), add optional tokenProvider refresh hook. Keep all else in sync
// with upstream to make future updates cheap. Upstream MOQT version: moqt-18.
```

- [ ] **Step 2: Fork edit A — drop `serverCertificateHashes` in `#connect()`**

Replace the `WebTransport` construction in `#connect()`:

```js
    this.#wt = new WebTransport(this.#conf.url, {
      serverCertificateHashes: [
        { algorithm: "sha-256", value: this.#fingerprint.buffer },
      ],
      protocols: [MediaMTXMoQReader.#MOQT_VERSION],
    });
```

with (a trusted cert needs no pinning):

```js
    this.#wt = new WebTransport(this.#conf.url, {
      protocols: [MediaMTXMoQReader.#MOQT_VERSION],
    });
```

- [ ] **Step 3: Fork edit B — drop the fingerprint fetch and add the token refresh in `#start()`**

Replace `#start()`:

```js
  #start() {
    this.#fetchFingerprint()
      .then(() => this.#connect())
      .then(() => this.#setup())
      .then(() => this.#subscribeCatalog())
      .then(() => this.#subscribeAllTracks())
      .then(() => this.#drainDataStreams())
      .catch((err) => this.#handleError(err.message));
  }
```

with (refresh the token first, then connect straight away — no fingerprint round-trip):

```js
  #start() {
    Promise.resolve(
      this.#conf.tokenProvider ? this.#conf.tokenProvider() : this.#conf.token,
    )
      .then((token) => {
        if (this.#conf.tokenProvider) this.#conf.token = token;
      })
      .then(() => this.#connect())
      .then(() => this.#setup())
      .then(() => this.#subscribeCatalog())
      .then(() => this.#subscribeAllTracks())
      .then(() => this.#drainDataStreams())
      .catch((err) => this.#handleError(err.message));
  }
```

The now-unused `#fetchFingerprint()` method, the `#fingerprint` field, and `fingerprintUrl` config are left in place but never called (keeps the diff to the two behavioral edits above; dead code is harmless and eases upstream re-sync). `#authHeader()` likewise stays.

**Spike alignment:** if Task 1 found a different `#MOQT_VERSION` or a URL shape that needs adjusting, apply that here too and note it in the header comment.

- [ ] **Step 4: Wire the reader into Vite**

In `vite.config.js`, add the file to the `laravel({ input: [...] })` array:

```js
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/reader.js', 'resources/js/moq-reader.js'],
```

- [ ] **Step 5: Verify the bundle builds**

Run: `npm run build`
Expected: build succeeds and the manifest includes `resources/js/moq-reader.js`.

- [ ] **Step 6: Commit**

```bash
git add resources/js/moq-reader.js vite.config.js
git commit -m "feat: vendor MediaMTX MoQ reader (fork: no fingerprint/pinning, token refresh hook)"
```

---

## Task 4: The `/moq` player view

**Files:**
- Create: `resources/views/moq-player.blade.php`

**Interfaces:**
- Consumes: view data from Task 2 (`$moqBaseUrl`, `$tokenEndpoint`, `$embedSource`), `window.MediaMTXMoQReader` from Task 3.

- [ ] **Step 1: Create the Blade view**

Create `resources/views/moq-player.blade.php`. This mirrors `player.blade.php`'s token flow + teardown, swaps the `<video>` for the reader's `<canvas>` container, adds a mute button, and guards unsupported browsers with a WHEP fallback link:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Media-over-QUIC Player</title>
@vite('resources/js/moq-reader.js')
<style>
  html, body { margin: 0; padding: 0; height: 100%; width: 100%; background: black;
    display: flex; justify-content: center; align-items: center; }
  #video { width: 100%; height: 100%; position: relative; }
  #video canvas { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: contain; }
  #loader { position: absolute; display: flex; flex-direction: column; align-items: center;
    justify-content: center; gap: 10px; pointer-events: none; }
  #spinner.hidden { display: none; }
  #status { color: #ccc; font-family: sans-serif; font-size: 0.9rem; pointer-events: none; text-align: center; }
  #status.error { color: #ff6b6b; }
  .spinner { width: 36px; height: 36px; border: 3px solid rgba(255,255,255,0.12);
    border-top-color: rgba(255,255,255,0.55); border-radius: 50%; animation: spin 0.75s linear infinite; display: inline-block; }
  @keyframes spin { to { transform: rotate(360deg); } }
  #mute { position: absolute; bottom: 20px; right: 20px; width: 48px; height: 48px;
    border-radius: 50%; background: rgba(0,0,0,0.5); color: #fff; border: none; cursor: pointer;
    font-family: sans-serif; font-size: 0.7rem; display: none; }
  #mute.visible { display: block; }
  #unsupported { color: #fff; font-family: sans-serif; text-align: center; padding: 2rem; max-width: 32rem; }
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
<button id="mute" type="button">Unmute</button>
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
const muted = params.get("muted") !== "false";

function setStatus(msg, isError = false) { statusEl.textContent = msg; statusEl.classList.toggle("error", isError); }
function showLoader(v) { spinnerEl.classList.toggle("hidden", !v); }

if (!stream) {
  document.body.innerHTML = '<p style="color:#fff;font-family:sans-serif;text-align:center;margin-top:2rem">Missing required <code>?stream=</code> parameter.</p>';
  throw new Error("Missing stream parameter");
}

// MoQ needs WebTransport + WebCodecs. Fall back to the WHEP player when unavailable.
if (typeof window.WebTransport === "undefined" || typeof window.VideoDecoder === "undefined") {
  const fallback = `/play${window.location.search}`;
  document.getElementById("loader").classList.add("hidden");
  unsupportedEl.classList.remove("hidden");
  unsupportedEl.innerHTML =
    'Your browser does not support Media-over-QUIC playback. ' +
    'Try Chrome/Edge 110+, Firefox 130+, or Safari 26.4+, or ' +
    '<a href="' + fallback + '">open the standard player</a>.';
  throw new Error("WebTransport/WebCodecs unsupported");
}

let tokenAbort = null;
let tearingDown = false;

// Fetch a fresh read JWT. Reused by the reader on every (re)connect via tokenProvider,
// so a stream that reconnects after the 60s TTL still authorizes. Mirrors /play's gate:
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
      if (!res.ok) throw new Error(`Token request denied (${res.status})`);
      const data = await res.json();
      if (!data.token) throw new Error("JWT not received");
      return data.token;
    } catch (err) {
      if (err.name === "AbortError") throw err;
      if (err.message.startsWith("Token request denied") || attempt === 2) throw err;
    }
  }
  throw new Error("Failed to obtain token");
}

let reader = null;

function startPlayer() {
  setStatus("Connecting…");
  showLoader(true);

  reader = new MediaMTXMoQReader({
    // Spike (Task 1) confirms the exact path shape; default to <base>/<stream>/moq.
    url: `${MOQ_BASE_URL}/${stream}/moq`,
    tokenProvider: fetchToken,
    videoElement: videoEl,
    onError: (msg) => { setStatus(msg, true); showLoader(true); },
    onSubscribed: () => { setStatus(""); showLoader(false); },
    onAudioMuted: (isMuted) => { muteEl.classList.toggle("visible", isMuted); },
  });
}

muteEl.addEventListener("click", () => { if (reader) reader.unmute(); });

function teardown() {
  tearingDown = true;
  if (tokenAbort) tokenAbort.abort();
  // Reader has no public close(); dropping the page tears down the WebTransport.
}
window.addEventListener("pagehide", teardown);
window.addEventListener("beforeunload", teardown);

window.addEventListener("DOMContentLoaded", startPlayer);
</script>

</body>
</html>
```

**Notes for the implementer:**
- The `muted` param is honored implicitly: the reader starts audio suspended under autoplay policy and fires `onAudioMuted(true)`, which reveals the Unmute button. There is no separate autoplay/controls handling — MoQ renders to a canvas, so those `<video>` attributes don't apply.
- The `url` template (`/${stream}/moq`) is the Task-1-confirmed default; adjust to the spike's finding if different.

- [ ] **Step 2: Run the controller tests (now that the view exists)**

Run: `php artisan test tests/Feature/MoqPlayerControllerTest.php`
Expected: all 4 tests PASS (403 path + 3 view-injection assertions).

- [ ] **Step 3: Build assets to confirm the view's `@vite` resolves**

Run: `npm run build`
Expected: success (no missing-input error for `moq-reader.js`).

- [ ] **Step 4: Commit**

```bash
git add resources/views/moq-player.blade.php
git commit -m "feat: add /moq player view (MoQ reader + WHEP fallback)"
```

---

## Task 5: Server config & deploy guide

**Files:**
- Create: `docs/moq-player.md`

**Interfaces:** none (documentation + ops). No app code.

- [ ] **Step 1: Write the ops/deploy guide**

Create `docs/moq-player.md` covering, concretely:

- **Goal:** what `/moq` is and how it differs from `/play` (direct-UDP MoQ vs proxied WHEP).
- **MediaMTX config** — the exact `mediamtx.yml` block:

  ```yaml
  moq: yes
  moqHTTP2Address: :8892
  moqHTTP3Address: :8892
  moqServerCert: /etc/letsencrypt/live/moq.jrta.online/fullchain.pem
  moqServerKey:  /etc/letsencrypt/live/moq.jrta.online/privkey.pem
  moqAllowOrigins: ["https://<app-origin>"]
  moqTrustedProxies: []
  ```

- **Cert (certbot, DNS-01 via Cloudflare)** — issue for `moq.jrta.online`; add a `--deploy-hook` that reloads MediaMTX so the QUIC listener picks up the renewed cert; ensure MediaMTX can read the cert files.
- **DNS + NAT** — DNS-only (grey-cloud) `moq.jrta.online` → public IP; forward **UDP 8892** through the CPE to the MediaMTX host. Note the origin-IP exposure trade-off (WebTransport can't be Cloudflare-proxied).
- **App env** — set `MEDIAMTX_MOQ_BASE_URL=https://moq.jrta.online:8892`.
- **Why UDP-only / not reverse-proxied** — WebTransport = HTTP/3/QUIC; Apache/Nginx/Cloudflare can't proxy it.
- **Verify** — publish via WHIP, open `/moq?stream=<path>` in Chrome, confirm playback + unmute + reconnect; confirm the unsupported-browser fallback.

- [ ] **Step 2: Commit**

```bash
git add docs/moq-player.md
git commit -m "docs: add MoQ player server config & deploy guide"
```

---

## Task 6: README updates

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Update the README**

Add/adjust, keeping the table-of-contents in sync:
- **Routes:** document `GET /moq?stream=<path>` alongside `/play`, noting it reuses the `/api/read` gate.
- **Env vars:** add `MEDIAMTX_MOQ_BASE_URL` with a one-line description.
- **Architecture:** a short "Media-over-QUIC (MoQ)" note — separate direct-UDP media path, cert in MediaMTX, links to `docs/moq-player.md`.
- **Deploy/update steps:** reference the new server-side requirements (DNS-only record, UDP 8892 forward, certbot deploy-hook).

- [ ] **Step 2: Verify no broken TOC anchors**

Skim the rendered README TOC; confirm the new section anchors resolve.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: document /moq route, env var, and MoQ topology in README"
```

---

## Final Verification

- [ ] **Full test suite:** `composer run test` — MoQ controller tests pass; nothing else regressed.
- [ ] **Lint:** `./vendor/bin/pint` — clean.
- [ ] **Build:** `npm run build` — succeeds with both readers bundled.
- [ ] **Manual E2E (post-infra):** publish via WHIP → `/moq?stream=<path>` in a supported browser → playback, unmute, reconnect; latency vs WHEP; unsupported-browser fallback shows the `/play` link.

---

## Self-Review Notes (spec coverage)

- Spec §1 (server config) → Task 5. §2 (route/controller) → Task 2. §3 (config) → Task 2. §4 (vendored reader fork) → Task 3. §5 (view) → Task 4. §6 (token TTL/refresh) → Task 3 `tokenProvider` + Task 4 `fetchToken`. §7 (out of scope) → honored (no monitor/Filament changes). §8 (testing) → Task 1 spike + Task 2 tests + Final Verification. §9 (docs) → Tasks 5–6.
