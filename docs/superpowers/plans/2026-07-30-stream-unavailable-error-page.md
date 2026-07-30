# Stream-Unavailable Error Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Laravel's stock 403 page and the players' raw red status text with one shared, on-brand "Stream unavailable" screen for every viewer-eligibility rejection.

**Architecture:** A single self-contained Blade partial (`partials/stream-unavailable`) carries the markup + scoped CSS. A thin standalone document (`errors/stream-unavailable`) includes it and is returned with a 403 status from the five `abort(403)` call sites. The same partial is `@include`d as a hidden fixed-position overlay in both players, revealed by JS only when `POST /api/read` returns 403 after the page has already loaded.

**Tech Stack:** Laravel 12 / PHP 8.2+, Blade, plain inline CSS (deliberately **no** Tailwind/Vite — this page renders inside small third-party iframes and must not depend on the asset build), PHPUnit.

## Global Constraints

- **Copy is fixed and reason-agnostic.** Heading: exactly `Stream unavailable`. Subtext: exactly `This video isn't available here.` Never vary the message by rejection cause (blocked IP vs. disallowed embed origin vs. direct-playback-disabled) — that would let an attacker probe which gate tripped.
- **HTTP status stays `403`** at every call site. Only the response body changes.
- **No Tailwind, no `@vite`, no external assets** in the partial or the error page. All CSS inline in the partial. It must render identically and instantly inside a tiny cross-origin iframe.
- **Do not modify `resources/js/moq-reader.js`** — it is a vendored fork of MediaMTX's reader kept minimal for cheap upstream re-sync.
- **Out of scope, leave exactly as-is:** the `Missing ?stream= parameter` fallback, the WHEP retry-exhaustion message `Stream unavailable right now.`, the MoQ unsupported-browser and missing-`MOQ_BASE_URL` messages, and every non-403 error path (500 / 422 / network).
- Run `./vendor/bin/pint` before each commit that touches PHP.

### Pre-existing test baseline (NOT caused by this work)

`composer run test` on `production` at the start of this plan reports **8 failed, 102 passed**. Do not try to fix these; just confirm the count does not grow (except where a task explicitly says it fixes some):

- `Tests\Feature\ExampleTest > the application returns a successful…`
- `Tests\Feature\PlayerAccessMiddlewareTest` — 4 failures, all the tests *expecting 200* (`blacklist mode allows unlisted ip`, `blacklist mode allows ip not in list`, `whitelist mode allows listed ip`, `whitelist mode with 0000 allows any ip`). They fail because `PlayerController`'s direct-playback gate now 403s a non-embedded `/play`, which those tests predate.
- `Tests\Feature\PlayerControllerTest` — 3 failures (`play route returns 200`, `injects whep base url`, `injects token endpoint`), all `SQLSTATE… no such table: player_settings` because the class lacks `RefreshDatabase`. **Task 3 fixes these three** as a necessary side effect of adding a test to that file.

The 403-expecting tests in `PlayerAccessMiddlewareTest` and `MoqPlayerControllerTest` currently **pass** and must keep passing.

---

## File Structure

| File | Responsibility |
|---|---|
| `resources/views/partials/stream-unavailable.blade.php` | **Create.** The only place the markup, copy, and CSS live. Optional `$hidden` flag so it can be included pre-hidden as an overlay. |
| `resources/views/errors/stream-unavailable.blade.php` | **Create.** Minimal full HTML document wrapping the partial; what the 403 responses return. |
| `app/Http/Middleware/PlayerAccessMiddleware.php` | **Modify.** 3 × `abort(403)` → `response()->view(...)`. |
| `app/Http/Controllers/PlayerController.php` | **Modify.** 1 × `abort(403)` → `response()->view(...)`; return type widens. |
| `app/Http/Controllers/MoqPlayerController.php` | **Modify.** Same as above. |
| `resources/views/player.blade.php` | **Modify.** Include partial as hidden overlay; show it on a 403 from `/api/read`. |
| `resources/views/moq-player.blade.php` | **Modify.** Same, via a module-scoped denial-status variable. |
| `tests/Feature/PlayerAccessMiddlewareTest.php` | **Modify.** Assert the branded body on the two existing 403 tests. |
| `tests/Feature/PlayerControllerTest.php` | **Modify.** Add `RefreshDatabase`; add a 403-body test. |
| `tests/Feature/MoqPlayerControllerTest.php` | **Modify.** Assert branded body on the existing 403 test. |
| `docs/stream-unavailable-error-page.md` | **Create.** Repo convention: document every change. |
| `README.md` | **Modify.** Note the branded rejection page under the player sections. |

---

### Task 1: Shared partial + standalone error page, wired into `PlayerAccessMiddleware`

This task delivers the visual and proves it end-to-end through the middleware's IP gates.

**Files:**
- Create: `resources/views/partials/stream-unavailable.blade.php`
- Create: `resources/views/errors/stream-unavailable.blade.php`
- Modify: `app/Http/Middleware/PlayerAccessMiddleware.php` (lines ~31-41 and ~60-65)
- Test: `tests/Feature/PlayerAccessMiddlewareTest.php`

**Interfaces:**
- Consumes: nothing (first task).
- Produces:
  - Blade view name `partials.stream-unavailable`, accepting one optional variable `$hidden` (bool, default false). When true the root element also gets the class `hidden`.
  - Root element: `<div id="stream-unavailable" class="stream-unavailable">`.
  - Blade view name `errors.stream-unavailable`, taking **no** variables.
  - The literal strings `Stream unavailable` and `This video isn't available here.` appear in the rendered body — later tasks assert on `Stream unavailable`.

- [ ] **Step 1: Write the failing test**

Add these two tests to `tests/Feature/PlayerAccessMiddlewareTest.php` (keep the existing `setUp()` and all existing tests untouched):

```php
    public function test_blocked_ip_renders_branded_error_page(): void
    {
        PlayerIpRule::create(['ip' => '1.2.3.4']);

        $response = $this->get('/play', ['REMOTE_ADDR' => '1.2.3.4']);

        $response->assertStatus(403);
        $response->assertSee('Stream unavailable');
        $response->assertSee("This video isn't available here.", escape: false);
    }

    public function test_whitelist_rejection_renders_branded_error_page(): void
    {
        PlayerSetting::where('key', 'ip_mode')->update(['value' => 'whitelist']);

        $response = $this->get('/play', ['REMOTE_ADDR' => '9.9.9.9']);

        $response->assertStatus(403);
        $response->assertSee('Stream unavailable');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter PlayerAccessMiddlewareTest`
Expected: the two new tests FAIL — status is already 403, but the body is Laravel's stock error page, so `assertSee('Stream unavailable')` fails with "Failed asserting that … contains 'Stream unavailable'". (The 4 pre-existing baseline failures in this class also appear — ignore them.)

- [ ] **Step 3: Create the shared partial**

Create `resources/views/partials/stream-unavailable.blade.php`:

```blade
{{-- Shown to viewers who are not eligible to watch (blocked IP, disallowed embed
     origin, direct playback off). The copy is deliberately identical for every
     cause so it never reveals which gate rejected the request. --}}
<div id="stream-unavailable" class="stream-unavailable{{ ($hidden ?? false) ? ' hidden' : '' }}">
    <svg class="su-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <circle cx="12" cy="12" r="9.25" fill="none" stroke="currentColor" stroke-width="1.4" />
        <path d="M9.6 8.5v7l5.4-3.5z" fill="currentColor" />
        <line x1="5.6" y1="18.4" x2="18.4" y2="5.6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
    </svg>
    <h1 class="su-title">Stream unavailable</h1>
    <p class="su-text">This video isn't available here.</p>
</div>
<style>
    .stream-unavailable {
        position: fixed;
        inset: 0;
        z-index: 10;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 14px;
        padding: 24px;
        box-sizing: border-box;
        background: radial-gradient(ellipse at center, #141414 0%, #000 72%);
        color: #fff;
        font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        text-align: center;
    }
    .stream-unavailable.hidden { display: none; }
    .stream-unavailable .su-icon {
        width: clamp(44px, 14vmin, 76px);
        height: auto;
        color: rgba(255, 255, 255, 0.42);
        animation: su-breathe 3s ease-in-out infinite;
    }
    .stream-unavailable .su-title {
        margin: 0;
        font-size: clamp(0.95rem, 3.4vmin, 1.25rem);
        font-weight: 600;
        letter-spacing: 0.01em;
        color: rgba(255, 255, 255, 0.92);
    }
    .stream-unavailable .su-text {
        margin: 0;
        max-width: 26rem;
        font-size: clamp(0.78rem, 2.6vmin, 0.95rem);
        line-height: 1.5;
        color: rgba(255, 255, 255, 0.55);
    }
    @keyframes su-breathe {
        0%, 100% { opacity: 0.55; }
        50%      { opacity: 1; }
    }
    @media (prefers-reduced-motion: reduce) {
        .stream-unavailable .su-icon { animation: none; opacity: 0.75; }
    }
</style>
```

- [ ] **Step 4: Create the standalone error page**

Create `resources/views/errors/stream-unavailable.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex" />
<title>Stream Unavailable</title>
<style>
  html, body {
    margin: 0;
    padding: 0;
    height: 100%;
    width: 100%;
    background: #000;
    overflow: hidden;
  }
</style>
</head>
<body>
@include('partials.stream-unavailable')
</body>
</html>
```

- [ ] **Step 5: Wire the middleware's three rejection points**

In `app/Http/Middleware/PlayerAccessMiddleware.php`, replace the IP-gate block. Find:

```php
        if ($mode === 'whitelist') {
            // 0.0.0.0 means allow all
            if (! in_array('0.0.0.0', $ipRules, true) && ! in_array($ip, $ipRules, true)) {
                abort(403);
            }
        } else {
            // blacklist mode: block listed IPs, allow all others
            if (in_array($ip, $ipRules, true)) {
                abort(403);
            }
        }
```

Replace with:

```php
        if ($mode === 'whitelist') {
            // 0.0.0.0 means allow all
            if (! in_array('0.0.0.0', $ipRules, true) && ! in_array($ip, $ipRules, true)) {
                return $this->deny();
            }
        } else {
            // blacklist mode: block listed IPs, allow all others
            if (in_array($ip, $ipRules, true)) {
                return $this->deny();
            }
        }
```

Then find the referrer-gate abort:

```php
                $allowed = $referrers->contains(fn ($rule) => $rule->matches($source));
                if (! $allowed) {
                    abort(403);
                }
```

Replace with:

```php
                $allowed = $referrers->contains(fn ($rule) => $rule->matches($source));
                if (! $allowed) {
                    return $this->deny();
                }
```

Finally add this private method at the end of the class, after `handle()`:

```php
    private function deny(): Response
    {
        return response()->view('errors.stream-unavailable', [], 403);
    }
```

And add the import alongside the existing `use Illuminate\Http\Request;`:

```php
use Illuminate\Http\Response;
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter PlayerAccessMiddlewareTest`
Expected: the two new tests PASS. The two existing 403 tests (`blacklist mode blocks listed ip`, `whitelist mode blocks unlisted ip`) still PASS. The 4 baseline failures are unchanged — total for this class should be **4 failed, 5 passed**.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint
git add resources/views/partials/stream-unavailable.blade.php resources/views/errors/stream-unavailable.blade.php app/Http/Middleware/PlayerAccessMiddleware.php tests/Feature/PlayerAccessMiddlewareTest.php
git commit -m "feat(player): branded error page for viewers rejected by the IP/embed gates"
```

---

### Task 2: Wire `MoqPlayerController`'s direct-playback gate

**Files:**
- Modify: `app/Http/Controllers/MoqPlayerController.php:11-42`
- Test: `tests/Feature/MoqPlayerControllerTest.php`

**Interfaces:**
- Consumes: view `errors.stream-unavailable` from Task 1 (no variables).
- Produces: `MoqPlayerController::__invoke()` return type widens from `View` to `View|Response` (`Illuminate\Http\Response`).

- [ ] **Step 1: Write the failing test**

In `tests/Feature/MoqPlayerControllerTest.php`, replace the existing `test_moq_route_403_when_direct_disabled_and_not_embedded` method with:

```php
    public function test_moq_route_403_when_direct_disabled_and_not_embedded(): void
    {
        $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->withoutVite()
            ->get('/moq?stream=live/a')
            ->assertStatus(403)
            ->assertSee('Stream unavailable');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter MoqPlayerControllerTest`
Expected: that one test FAILS on the new `assertSee` (status 403 is already correct); the other 3 tests in the class PASS.

- [ ] **Step 3: Write the implementation**

In `app/Http/Controllers/MoqPlayerController.php`, change the import block. Find:

```php
use Illuminate\Http\Request;
use Illuminate\View\View;
```

Replace with:

```php
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
```

Change the signature. Find:

```php
    public function __invoke(Request $request): View
```

Replace with:

```php
    public function __invoke(Request $request): View|Response
```

Then find:

```php
        if (! $isEmbed && ! $directAllowed) {
            abort(403);
        }
```

Replace with:

```php
        if (! $isEmbed && ! $directAllowed) {
            return response()->view('errors.stream-unavailable', [], 403);
        }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter MoqPlayerControllerTest`
Expected: PASS — 4 passed, 0 failed.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint
git add app/Http/Controllers/MoqPlayerController.php tests/Feature/MoqPlayerControllerTest.php
git commit -m "feat(moq): return the branded error page when direct playback is disabled"
```

---

### Task 3: Wire `PlayerController`'s direct-playback gate

Adding a DB-touching test to `PlayerControllerTest` requires `RefreshDatabase`, whose absence is also the cause of that class's 3 pre-existing baseline failures — so this task fixes those three too.

**Files:**
- Modify: `app/Http/Controllers/PlayerController.php:11-43`
- Test: `tests/Feature/PlayerControllerTest.php`

**Interfaces:**
- Consumes: view `errors.stream-unavailable` from Task 1 (no variables).
- Produces: `PlayerController::__invoke()` return type widens from `View` to `View|Response` (`Illuminate\Http\Response`).

- [ ] **Step 1: Write the failing test**

Replace the entire contents of `tests/Feature/PlayerControllerTest.php` with:

```php
<?php

namespace Tests\Feature;

use App\Http\Middleware\PlayerAccessMiddleware;
use App\Models\PlayerSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerControllerTest extends TestCase
{
    use RefreshDatabase;

    private function allowDirect(): void
    {
        // Firing the model event busts the cached 'player_setting:allow_direct_playback'.
        PlayerSetting::create(['key' => 'allow_direct_playback', 'value' => '1']);
    }

    public function test_play_route_returns_200(): void
    {
        $this->allowDirect();

        $response = $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->withoutVite()
            ->get('/play');

        $response->assertStatus(200);
    }

    public function test_play_route_injects_whep_base_url(): void
    {
        $this->allowDirect();
        config(['services.mediamtx.stream_base_url' => 'https://example-stream.test']);

        $response = $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->withoutVite()
            ->get('/play');

        $response->assertStatus(200);
        $response->assertSee('https://example-stream.test', escape: false);
    }

    public function test_play_route_injects_token_endpoint(): void
    {
        $this->allowDirect();

        $response = $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->withoutVite()
            ->get('/play');

        $response->assertStatus(200);
        $response->assertSee('/api/read', escape: false);
    }

    public function test_play_route_403_renders_branded_error_page(): void
    {
        $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->withoutVite()
            ->get('/play')
            ->assertStatus(403)
            ->assertSee('Stream unavailable');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter PlayerControllerTest`
Expected: `test_play_route_403_renders_branded_error_page` FAILS on `assertSee` (status 403 already correct). The other 3 tests now PASS (they were the baseline `no such table: player_settings` failures — `RefreshDatabase` + `allowDirect()` fixes them).

- [ ] **Step 3: Write the implementation**

In `app/Http/Controllers/PlayerController.php`, change the import block. Find:

```php
use Illuminate\Http\Request;
use Illuminate\View\View;
```

Replace with:

```php
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
```

Change the signature. Find:

```php
    public function __invoke(Request $request): View
```

Replace with:

```php
    public function __invoke(Request $request): View|Response
```

Then find:

```php
        if (! $isEmbed && ! $directAllowed) {
            abort(403);
        }
```

Replace with:

```php
        if (! $isEmbed && ! $directAllowed) {
            return response()->view('errors.stream-unavailable', [], 403);
        }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter PlayerControllerTest`
Expected: PASS — 4 passed, 0 failed.

- [ ] **Step 5: Run the full suite to confirm the baseline improved and nothing regressed**

Run: `composer run test`
Expected: **5 failed, 108 passed.** (Down from 8 failed: the 3 `PlayerControllerTest` failures are fixed. The 5 remaining are the `ExampleTest` failure and the 4 `PlayerAccessMiddlewareTest` 200-expecting failures — both pre-existing and out of scope.)

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint
git add app/Http/Controllers/PlayerController.php tests/Feature/PlayerControllerTest.php
git commit -m "feat(player): return the branded error page when direct playback is disabled"
```

---

### Task 4: In-page overlay for the WHEP player (`/play`)

Covers the case where the page loaded fine but `POST /api/read` later returns 403 (e.g. the IP was blocked after render).

**Files:**
- Modify: `resources/views/player.blade.php` (body ~line 207, JS ~lines 225-231, ~465-481, ~541-552)

**Interfaces:**
- Consumes: `partials.stream-unavailable` from Task 1, included with `['hidden' => true]`.
- Produces: no cross-file interface. Internal to this view: `showIneligible()` and the module-scoped `let ineligible = false;` flag.

- [ ] **Step 1: Include the partial as a hidden overlay**

In `resources/views/player.blade.php`, find the closing of the stage div followed by the script tag:

```blade
</div>

<script>
const WHEP_BASE_URL = "{{ $whepBaseUrl }}";
```

Replace with:

```blade
</div>

@include('partials.stream-unavailable', ['hidden' => true])

<script>
const WHEP_BASE_URL = "{{ $whepBaseUrl }}";
```

- [ ] **Step 2: Add the element handle and the show function**

Find the element-handle block:

```js
const statusEl = document.getElementById("status");
const videoEl = document.getElementById("myvideo");
const bgVideoEl = document.getElementById("bg-video");
const spinnerEl = document.getElementById("spinner");
const tapOverlay = document.getElementById("tap-overlay");
const unmuteBtn = document.getElementById("unmute-btn");
```

Replace with:

```js
const statusEl = document.getElementById("status");
const videoEl = document.getElementById("myvideo");
const bgVideoEl = document.getElementById("bg-video");
const spinnerEl = document.getElementById("spinner");
const tapOverlay = document.getElementById("tap-overlay");
const unmuteBtn = document.getElementById("unmute-btn");
const ineligibleEl = document.getElementById("stream-unavailable");
```

Then find the `setStatus` / `showLoader` helpers:

```js
function setStatus(msg, isError = false) {
  statusEl.textContent = msg;
  statusEl.classList.toggle("error", isError);
}
function showLoader(v) { spinnerEl.classList.toggle("hidden", !v); }
```

Replace with:

```js
function setStatus(msg, isError = false) {
  statusEl.textContent = msg;
  statusEl.classList.toggle("error", isError);
}
function showLoader(v) { spinnerEl.classList.toggle("hidden", !v); }

// The gate denied this viewer (403 from /api/read). Terminal — no retry.
let ineligible = false;
function showIneligible() {
  ineligible = true;
  setStatus("");
  showLoader(false);
  tapOverlay.classList.add("hidden");
  unmuteBtn.classList.add("hidden");
  ineligibleEl.classList.remove("hidden");
}
```

- [ ] **Step 3: Branch on the 403 in the token-failure path**

Find the catch block in `initPlayer()`:

```js
  try {
    await tokenPromise;
  } catch (err) {
    clearWatchdog();
    setStatus(`Couldn't load stream: ${err.message}`, true);
    showLoader(false);
    starting = false;
    return;
  }
```

Replace with:

```js
  try {
    await tokenPromise;
  } catch (err) {
    clearWatchdog();
    starting = false;
    if (err.message === "Token request denied (403)") {
      showIneligible();
      return;
    }
    setStatus(`Couldn't load stream: ${err.message}`, true);
    showLoader(false);
    return;
  }
```

- [ ] **Step 4: Stop the visibility-change handler from re-POSTing after a denial**

Find:

```js
document.addEventListener("visibilitychange", () => {
  if (document.visibilityState === "visible" && (!reader || videoEl.paused || videoEl.readyState < 2)) {
```

Replace with:

```js
document.addEventListener("visibilitychange", () => {
  if (ineligible) return;
  if (document.visibilityState === "visible" && (!reader || videoEl.paused || videoEl.readyState < 2)) {
```

- [ ] **Step 5: Verify the page still renders and the overlay ships hidden**

Run: `php artisan test --filter PlayerControllerTest`
Expected: PASS — 4 passed. (`test_play_route_returns_200` renders the whole player view including the new include, so a Blade syntax error would fail here.)

Then manually confirm the overlay markup is present but hidden in the served player:

Run: `php artisan tinker --execute="\App\Models\PlayerSetting::updateOrCreate(['key'=>'allow_direct_playback'],['value'=>'1']); echo str_contains(view('player', ['whepBaseUrl'=>'https://x.test','tokenEndpoint'=>'/api/read','playbackEnabled'=>true,'embedSource'=>''])->render(), 'stream-unavailable hidden') ? 'OVERLAY OK' : 'OVERLAY MISSING';"`
Expected: prints `OVERLAY OK`

- [ ] **Step 6: Commit**

```bash
git add resources/views/player.blade.php
git commit -m "feat(player): show the branded error screen when the read token is denied"
```

---

### Task 5: In-page overlay for the MoQ player (`/moq`)

**Files:**
- Modify: `resources/views/moq-player.blade.php` (body ~line 96, JS ~lines 102-128, ~153-190, ~207-216)

**Interfaces:**
- Consumes: `partials.stream-unavailable` from Task 1, included with `['hidden' => true]`.
- Produces: no cross-file interface. Internal to this view: `showIneligible()` and the module-scoped `let deniedStatus = null;`.

**Note:** the vendored reader passes only `err.message` (a string) to `onError`, so the HTTP status is not available there. Record it in a module-scoped variable inside `fetchToken()` instead. **Do not edit `resources/js/moq-reader.js`.**

- [ ] **Step 1: Include the partial as a hidden overlay**

In `resources/views/moq-player.blade.php`, find:

```blade
<button id="mute" type="button">🔇 Unmute</button>
<div id="unsupported" class="hidden"></div>
```

Replace with:

```blade
<button id="mute" type="button">🔇 Unmute</button>
<div id="unsupported" class="hidden"></div>

@include('partials.stream-unavailable', ['hidden' => true])
```

- [ ] **Step 2: Add the element handle and the show function**

Find:

```js
const statusEl = document.getElementById("status");
const spinnerEl = document.getElementById("spinner");
const muteEl = document.getElementById("mute");
const videoEl = document.getElementById("video");
const unsupportedEl = document.getElementById("unsupported");
```

Replace with:

```js
const statusEl = document.getElementById("status");
const spinnerEl = document.getElementById("spinner");
const muteEl = document.getElementById("mute");
const videoEl = document.getElementById("video");
const unsupportedEl = document.getElementById("unsupported");
const ineligibleEl = document.getElementById("stream-unavailable");
```

Then find `showFatal` and add `showIneligible` after it:

```js
// Render a terminal, non-retryable message (unsupported browser, misconfig, access denied).
function showFatal(html) {
  document.getElementById("loader").classList.add("hidden");
  unsupportedEl.classList.remove("hidden");
  unsupportedEl.innerHTML = html;
}
```

Replace with:

```js
// Render a terminal, non-retryable message (unsupported browser, misconfig, access denied).
function showFatal(html) {
  document.getElementById("loader").classList.add("hidden");
  unsupportedEl.classList.remove("hidden");
  unsupportedEl.innerHTML = html;
}

// The gate denied this viewer (403 from /api/read). Terminal — no retry.
function showIneligible() {
  document.getElementById("loader").classList.add("hidden");
  muteEl.classList.remove("visible");
  ineligibleEl.classList.remove("hidden");
}
```

- [ ] **Step 3: Record the denial status in `fetchToken`**

Find the start of the token fetch helper — the line declaring the abort controller and teardown flag:

```js
let tokenAbort = null;
let tearingDown = false;
```

Replace with:

```js
let tokenAbort = null;
let tearingDown = false;
// The reader's onError callback only receives err.message, so stash the HTTP
// status here to tell an eligibility denial (403) from other terminal failures.
let deniedStatus = null;
```

Then find, inside `fetchToken()`:

```js
      if (res.status >= 500 && attempt < 2) continue;
      if (!res.ok) {
        const err = new Error(`Access denied (${res.status})`);
```

Replace with:

```js
      if (res.status >= 500 && attempt < 2) continue;
      if (!res.ok) {
        deniedStatus = res.status;
        const err = new Error(`Access denied (${res.status})`);
```

- [ ] **Step 4: Branch on the 403 in the terminal-error path**

Find, in the reader config:

```js
    onError: (msg, terminal) => {
      if (terminal) {
        // The reader has stopped (e.g. access denied); show the reason, no spinner.
        showFatal(msg);
      } else {
```

Replace with:

```js
    onError: (msg, terminal) => {
      if (terminal) {
        // The reader has stopped. A 403 is an eligibility denial — show the branded
        // screen; anything else keeps the plain-text fatal box.
        if (deniedStatus === 403) {
          showIneligible();
        } else {
          showFatal(msg);
        }
      } else {
```

- [ ] **Step 5: Verify the page still renders and the overlay ships hidden**

Run: `php artisan test --filter MoqPlayerControllerTest`
Expected: PASS — 4 passed. (The 200 tests render the whole MoQ view, so a Blade syntax error fails here.)

Then confirm the overlay markup is present but hidden:

Run: `php artisan tinker --execute="echo str_contains(view('moq-player', ['moqBaseUrl'=>'https://x.test:8892','tokenEndpoint'=>'/api/read','playbackEnabled'=>true,'embedSource'=>''])->render(), 'stream-unavailable hidden') ? 'OVERLAY OK' : 'OVERLAY MISSING';"`
Expected: prints `OVERLAY OK`

- [ ] **Step 6: Commit**

```bash
git add resources/views/moq-player.blade.php
git commit -m "feat(moq): show the branded error screen when the read token is denied"
```

---

### Task 6: Manual browser verification

No JS test harness exists in this repo, so the two overlays need eyes on them. This task produces no code — only a confirmed observation, and a fix-and-recommit if something looks wrong.

**Files:** none (verification only; re-open Task 4/5 files if a defect is found)

**Interfaces:**
- Consumes: everything from Tasks 1-5.
- Produces: nothing.

- [ ] **Step 1: Start the dev environment**

Run: `composer run dev`
Leave it running. The app serves on `http://127.0.0.1:8000`.

- [ ] **Step 2: Verify the page-load rejection (standalone document)**

In the admin panel, open the `PlayerSettings` page and make sure **direct playback is off** (it is off by default). Then visit `http://127.0.0.1:8000/play?stream=live/test` directly in a browser tab.

Expected: the branded screen — dark radial background, softly pulsing slashed-play icon, "Stream unavailable" heading, "This video isn't available here." subtext. Vertically and horizontally centered. Browser devtools Network tab shows status **403**.

- [ ] **Step 3: Verify it holds up at iframe scale**

Create a scratch file at `/tmp/embed-test.html`:

```html
<!DOCTYPE html>
<html><body style="background:#333;padding:40px;font-family:sans-serif">
  <p style="color:#fff">320×180 iframe:</p>
  <iframe src="http://127.0.0.1:8000/play?stream=live/test" width="320" height="180" style="border:0"></iframe>
  <p style="color:#fff">240×135 iframe:</p>
  <iframe src="http://127.0.0.1:8000/play?stream=live/test" width="240" height="135" style="border:0"></iframe>
</body></html>
```

Open it in the browser (`file:///tmp/embed-test.html`).

Expected: at both sizes the icon and both lines of copy stay fully visible and centered, nothing clips or overflows, and no horizontal scrollbar appears inside the frames. (The `clamp()` sizing is what makes this work — if text clips at 240×135, tighten the lower bounds of the `clamp()` values in the partial, re-verify, and amend the Task 1 commit.)

- [ ] **Step 4: Verify the in-page overlay (post-load 403)**

Turn direct playback **on** in `PlayerSettings` so `/play` renders the real player. Add your own IP (`127.0.0.1`) to the **blocked IPs** list in the admin panel — this makes the page load succeed but `POST /api/read` return 403.

Reload `http://127.0.0.1:8000/play?stream=live/test`.

Expected: the spinner appears briefly, then the same branded screen replaces it — **not** small red text. Devtools shows the `/api/read` POST returning 403. Switching to another tab and back does not fire another `/api/read` POST (the `ineligible` guard).

- [ ] **Step 5: Confirm non-403 failures still use the old inline text**

Remove `127.0.0.1` from the blocked IPs list, and stop MediaMTX (or leave `MEDIAMTX_STREAM_BASE_URL` pointing at a dead host). Reload `/play?stream=live/test`.

Expected: the old behavior — spinner plus small grey/red status text ("Reconnecting stream…", eventually "Stream unavailable right now."), **not** the new branded overlay. This confirms the overlay is scoped to eligibility denials only.

- [ ] **Step 6: Reset the admin state you changed**

Restore `PlayerSettings` direct playback to its original value and confirm the blocked-IP list no longer contains `127.0.0.1`.

- [ ] **Step 7: Delete the scratch file**

```bash
rm /tmp/embed-test.html
```

---

### Task 7: Documentation

Repo convention (`CLAUDE.md`): document every meaningful change under `docs/`, and keep `README.md` in sync.

**Files:**
- Create: `docs/stream-unavailable-error-page.md`
- Modify: `README.md`

**Interfaces:**
- Consumes: everything from Tasks 1-5.
- Produces: nothing.

- [ ] **Step 1: Write the docs page**

Create `docs/stream-unavailable-error-page.md`:

```markdown
# Stream-Unavailable Error Page

## What

A single branded screen shown to any viewer who is not eligible to watch a stream, replacing
Laravel's stock 403 page and the players' raw red status text.

Heading: **Stream unavailable** · Subtext: **This video isn't available here.**

## Why

Ineligible viewers previously saw one of two unpolished things: Laravel's default 403 page (on a
page-load rejection), or a small red `Token request denied (403)` status line (when the page had
already loaded and the background token fetch was denied). Since the player is usually embedded in
a third-party site, that is what the embedding site's visitors saw in place of the video.

The copy is deliberately **identical for every rejection cause**. Varying it by cause (blocked IP
vs. disallowed embed origin vs. direct playback off) would let an attacker probe which gate
tripped.

## How

`resources/views/partials/stream-unavailable.blade.php` holds the markup, copy, and all CSS
inline — no Tailwind, no `@vite`, no external assets, so it renders instantly and identically
inside a small cross-origin iframe. It accepts one optional `$hidden` bool.

It is consumed two ways:

**1. Page-load rejection** — `resources/views/errors/stream-unavailable.blade.php` wraps the
partial in a minimal HTML document, returned with a 403 status from:

| Location | Cause |
|---|---|
| `PlayerAccessMiddleware::deny()` | blocked IP (blacklist mode), unlisted IP (whitelist mode), disallowed iframe embed origin |
| `PlayerController::__invoke()` | direct playback disabled and the request is not an embed |
| `MoqPlayerController::__invoke()` | same |

**2. Post-load rejection** — `player.blade.php` and `moq-player.blade.php` each `@include` the
partial with `['hidden' => true]` as a fixed-position overlay, revealed by `showIneligible()` when
`POST /api/read` returns **403** specifically.

- `player.blade.php` branches in `initPlayer()`'s token catch block and sets an `ineligible` flag
  so the `visibilitychange` handler stops re-POSTing to a gate that already denied.
- `moq-player.blade.php` records the status in a module-scoped `deniedStatus` inside
  `fetchToken()`, because the vendored reader passes only `err.message` to `onError`. The
  vendored `resources/js/moq-reader.js` is **not** modified.

Everything else is untouched: non-403 failures still use the existing inline status text, and the
MoQ `showFatal()` box still handles unsupported browsers and missing `MEDIAMTX_MOQ_BASE_URL`.

## Verify

```bash
php artisan test --filter PlayerAccessMiddlewareTest
php artisan test --filter PlayerControllerTest
php artisan test --filter MoqPlayerControllerTest
```

Manually: with direct playback off, `GET /play` returns 403 and renders the branded page; with it
on and your IP on the blocked list, the player loads and then swaps in the same screen.

## Deploy

View-only and controller changes — no migration, no new env var, no asset rebuild (the partial
carries its own CSS and is not part of the Vite bundle). Standard deploy plus:

```bash
php artisan view:clear
php artisan config:clear
```
```

- [ ] **Step 2: Update the README's `/play` section**

In `README.md`, under **Embeddable player (`GET /play`)** (around line 91), find:

```markdown
- **Direct playback** option — `PlayerSettings` controls whether playback starts automatically; `ReadTokenController`
  respects this.
```

Replace with:

```markdown
- **Direct playback** option — `PlayerSettings` controls whether playback starts automatically; `ReadTokenController`
  respects this.
- **Branded rejection screen** — viewers rejected by any gate (blocked IP, disallowed embed origin, direct playback
  off) get a single "Stream unavailable" page instead of a stock 403, both on page load and when the read token is
  denied mid‑session. See [docs/stream-unavailable-error-page.md](docs/stream-unavailable-error-page.md).
```

- [ ] **Step 3: Verify the README link resolves**

Run: `test -f docs/stream-unavailable-error-page.md && grep -q 'stream-unavailable-error-page.md' README.md && echo 'DOC LINK OK'`
Expected: prints `DOC LINK OK`

- [ ] **Step 4: Commit**

```bash
git add docs/stream-unavailable-error-page.md README.md
git commit -m "docs: document the stream-unavailable error page"
```

---

## Final Verification

- [ ] Run `composer run test`. Expected: **5 failed, 108 passed** — the 5 being the pre-existing `ExampleTest` failure plus the 4 `PlayerAccessMiddlewareTest` 200-expecting failures documented in Global Constraints. If any other test fails, it is a regression from this work.
- [ ] Run `./vendor/bin/pint --test`. Expected: no style violations.
- [ ] Confirm `git status` is clean and `git log --oneline -7` shows the six feature/docs commits.
