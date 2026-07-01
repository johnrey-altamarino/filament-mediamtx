# Fixing intermittent Cloudflare 520 errors on the Multistream Monitor

## Symptom

Opening the **Multistream Monitor** (`/monitor`) occasionally showed a full-screen
Cloudflare error page instead of the stream grid:

```
Web server is returning an unknown error — Error code 520
You (Browser) ✓  →  Manila (Cloudflare) ✓  →  studio.jrta.online (Host) ✗
```

The error was **intermittent** and **cleared on a plain refresh**. Cloudflare's own
diagnostic confirmed the browser and edge were fine — only the origin
(`studio.jrta.online`) failed.

## What a 520 actually means

Error 520 is Cloudflare's "the origin returned a response I can't understand" — an
empty reply, a dropped/reset connection, or a truncated/malformed response. A
*consistent* 520 means a hard misconfiguration; an *intermittent* one that clears on
refresh means the origin occasionally chokes on a request but is otherwise healthy.

## Root cause: aborted POST request bodies through Apache `mod_proxy_fcgi`

The origin runs Apache (event MPM) → PHP-FPM 8.4 via `mod_proxy_fcgi`, behind
Cloudflare. The Apache error log had the smoking gun:

```
[proxy_fcgi:error] (70008)Partial results are valid but processing is incomplete:
[client 162.158.136.156:12069] AH01075: Error dispatching request to :
(reading input brigade), referer: https://studio.jrta.online/monitor/play?stream=testbac1&controls=true
```

- `162.158.x` is a **Cloudflare** egress IP — this request came through the CDN.
- **"reading input brigade"** = Apache was reading the request **body** to forward to
  PHP-FPM. Only POSTs have a body, so this is a POST.
- **`70008` (`APR_INCOMPLETE`)** = the client connection went away **mid-body**. The
  request was aborted in flight.
- The **referer** pins it to the monitor.

### How the monitor manufactured aborted POSTs

1. `/monitor` renders up to 6 tiles, each an `<iframe src="/monitor/play?stream=…">`.
2. Each iframe's player fires **`POST /api/read`** (a JSON body) on load, then re-fires
   it on token refresh (~50s), a 15s watchdog, and every WebRTC `onError` retry — so
   there is a near-constant background of in-flight `POST /api/read` requests.
3. The page ran `wire:poll.12s="refresh"`. Every 12s Livewire re-rendered the
   component; when the active-publisher set changed (or pagination shifted), tiles were
   **added/removed → iframes destroyed/recreated**.
4. An iframe destroyed while its `POST /api/read` body was still being read → the
   browser aborted the connection → Apache logged `AH01075 (reading input brigade)` →
   returned a broken response → **Cloudflare 520**.

The full-screen 520 *overlay* had a second contributor: the **Livewire poll's own
`POST /livewire/update`** hitting the same fragile CF↔`mod_proxy_fcgi` path. When that
POST failed, Livewire's default behavior **renders the error response body as a
full-screen overlay** — which is literally the Cloudflare 520 page covering the monitor.

A plain refresh is a single clean top-level GET with nothing being torn down
concurrently, so nothing aborts — which is why refreshing "fixed" it.

### A red herring in the logs

`laravel.log` showed `cURL error 7: Failed to connect to 127.0.0.1 port 9997 after
0 ms` from the Sessions page. That was a **transient connection-refused during a
MediaMTX service restart**, unrelated to the 520. MediaMTX was healthy (port 9997
listening, no crash loop) once verified.

## The fix (three layers)

### 1. Stop the poll from reloading live iframes

`resources/views/livewire/multistream-monitor.blade.php` — added `wire:ignore` to the
iframe's wrapper `<div>`. Livewire's DOM morph now leaves every active tile's iframe
**untouched** across polls; an iframe is created/destroyed only when its stream truly
starts/stops. This removes the routine 12s teardown churn.

### 2. Abort the token fetch cleanly on teardown

`resources/views/player.blade.php`:

- The `POST /api/read` fetch now carries an `AbortController` signal.
- A `teardown()` handler bound to **`pagehide`** (fires when the monitor removes an
  iframe) and `beforeunload` (standalone tab) **aborts the in-flight POST** before the
  connection is yanked.
- `AbortError` is swallowed so teardown doesn't trigger a retry storm.

A client-initiated `abort()` closes the request cleanly instead of leaving Apache
reading a half-sent body — killing the `AH01075` at its source.

### 3. Make the Livewire poll resilient (don't blank the screen on a blip)

`resources/views/layouts/monitor.blade.php` — a Livewire `request` hook:

```js
document.addEventListener('livewire:init', () => {
    Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status === 0 || status >= 500) preventDefault(); // keep current view, retry next tick
        });
    });
});
```

On a transient failure (network drop, 5xx, Cloudflare 520/522) it `preventDefault()`s
Livewire's error overlay. Because the iframes are `wire:ignore`'d, the current view
stays intact (streams keep playing) and the next 12s poll silently recovers. Real 4xx
errors still surface.

## What each layer fixes

| Layer | Fixes |
|---|---|
| `wire:ignore` on iframes | Eliminates routine iframe teardown on every poll |
| `AbortController` on `/api/read` | Kills the tile-level `AH01075 (reading input brigade)` → 520 |
| Livewire `fail` hook | A blip no longer blanks the monitor with Cloudflare's 520 page |

## Remaining infra note

`AH01075 (reading input brigade)` on `mod_proxy_fcgi` behind Cloudflare is a known
pattern tied to aborted/interrupted request bodies and Cloudflare keep-alive
connection reuse. Once the client stops aborting requests there's nothing to "fix" in
Apache, but if poll-level 520s persist, check that `KeepAliveTimeout` isn't
aggressively short and consider raising the `wire:poll` interval to reduce POST volume.

## How to verify

Open the Multistream Monitor, let a stream stop while tiles are loading, and confirm:

```bash
tail -f /var/log/apache2/error.log   # no new AH01075 (reading input brigade) with /monitor/play referer
```

## Deploy

Git-pull on the server and, if views are cached, run `php artisan view:clear` as
`www-data`. No asset build needed — these are Blade + inline-script changes
(`reader.js` is unchanged).
