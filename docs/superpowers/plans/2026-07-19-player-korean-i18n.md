# Player Korean Localization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Render all viewer-facing status/error/overlay text in the `/play` WHEP player
(`resources/views/player.blade.php`) in Korean when the viewer's browser language is Korean,
falling back to English otherwise.

**Architecture:** Everything lives inside the existing inline `<script>` block of
`player.blade.php` — an `LANG` constant computed once from `navigator.languages`/`navigator.language`,
a `MESSAGES` dictionary (`en` + `ko`), and a `t(key, vars)` lookup helper. No new files, no route/
controller/build changes. Two thrown-error sites get a `.code` property so the composed
"Couldn't load stream: …" message can be classified instead of translating a raw JS error string.

**Tech Stack:** Plain vanilla JS inside a Blade view (no framework, no bundler entry point for this
script — it's inline, not part of `resources/js/reader.js`).

## Global Constraints

- Detection and rendering are entirely client-side JS — no controller, route, or query-parameter
  changes (spec: "Language Detection").
- Unrecognized/non-Korean browser language always falls back to English — there is no third
  language (spec: "Language Detection").
- Scope is `resources/views/player.blade.php` only. Do not touch `/moq` or any other view (spec:
  "Out of Scope").
- No new files, no Vite/build changes, no Laravel `lang/` directory (spec: "Translation Mechanism",
  "Out of Scope").
- Never let an uncoded/unexpected error surface raw untranslated English error text in the
  `ko` case — fall back to the generic translated phrase (spec: "Handling the composed load-error
  message").

---

### Task 1: Language detection, dictionary, and `t()` helper

**Files:**
- Modify: `resources/views/player.blade.php` (script block, right after the `EMBED_SOURCE` const)

**Interfaces:**
- Produces: `LANG` (`"en"` or `"ko"`), `MESSAGES` (`{en: {...}, ko: {...}}`), `t(key, vars?)` → `string`
  — consumed by Tasks 2 and 3.

- [ ] **Step 1: Add the detection + dictionary + helper block**

In `resources/views/player.blade.php`, find:

```js
const WHEP_BASE_URL = "{{ $whepBaseUrl }}";
const EMBED_SOURCE = @json($embedSource);

let reader = null;
```

Replace with:

```js
const WHEP_BASE_URL = "{{ $whepBaseUrl }}";
const EMBED_SOURCE = @json($embedSource);

// Viewer-facing text is localized to Korean when the browser reports a Korean
// language; anything else (including no signal at all) falls back to English.
const LANG = (navigator.languages || [navigator.language || ""])
  .some((l) => l.toLowerCase().startsWith("ko")) ? "ko" : "en";

const MESSAGES = {
  en: {
    loading: "Loading stream…",
    reconnecting: "Reconnecting stream…",
    unavailable: "Stream unavailable right now.",
    loadErrorPrefix: "Couldn't load stream: ",
    reasonTokenDenied: "access denied ({status})",
    reasonNoToken: "no token received",
    reasonGeneric: "connection error",
    missingStreamParam: "Missing required ?stream= parameter.",
    tapToPlay: "Tap to play",
    tapForSound: "Tap for sound",
  },
  ko: {
    loading: "스트림 불러오는 중…",
    reconnecting: "스트림 재연결 중…",
    unavailable: "현재 스트림을 사용할 수 없습니다.",
    loadErrorPrefix: "스트림을 불러올 수 없습니다: ",
    reasonTokenDenied: "접근이 거부되었습니다 ({status})",
    reasonNoToken: "토큰을 받지 못했습니다",
    reasonGeneric: "연결 오류",
    missingStreamParam: "필수 매개변수 ?stream= 이(가) 누락되었습니다.",
    tapToPlay: "탭하여 재생",
    tapForSound: "탭하여 소리 켜기",
  },
};

function t(key, vars) {
  const str = (MESSAGES[LANG] && MESSAGES[LANG][key]) || MESSAGES.en[key] || key;
  return vars ? str.replace(/\{(\w+)\}/g, (_, k) => vars[k]) : str;
}

let reader = null;
```

- [ ] **Step 2: Sanity-check `t()`'s logic in isolation**

This is pure string logic with no DOM/browser dependency, so verify it in Node before touching the
browser. Run:

```bash
node -e '
const MESSAGES = {
  en: { reasonTokenDenied: "access denied ({status})", missing: "Missing required ?stream= parameter." },
  ko: { reasonTokenDenied: "접근이 거부되었습니다 ({status})" },
};
function t(LANG, key, vars) {
  const str = (MESSAGES[LANG] && MESSAGES[LANG][key]) || MESSAGES.en[key] || key;
  return vars ? str.replace(/\{(\w+)\}/g, (_, k) => vars[k]) : str;
}
console.assert(t("en", "reasonTokenDenied", { status: 403 }) === "access denied (403)", "en interpolation");
console.assert(t("ko", "reasonTokenDenied", { status: 403 }) === "접근이 거부되었습니다 (403)", "ko interpolation");
console.assert(t("ko", "missing") === "Missing required ?stream= parameter.", "falls back to en when key absent in ko");
console.log("OK");
'
```

Expected output: `OK` with no assertion failures printed above it.

- [ ] **Step 3: Commit**

```bash
git add resources/views/player.blade.php
git commit -m "feat(player): add Korean/English i18n dictionary and detection"
```

---

### Task 2: Localize the loader status text and tap/unmute overlay labels

**Files:**
- Modify: `resources/views/player.blade.php` (overlay markup + script)

**Interfaces:**
- Consumes: `LANG`, `t(key, vars?)` from Task 1.

- [ ] **Step 1: Give the two overlay labels stable ids**

Find:

```html
    <span>Tap to play</span>
  </div>
  <button id="unmute-btn" class="hidden" type="button" aria-label="Tap for sound">
    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/></svg>
    <span>Tap for sound</span>
  </button>
```

Replace with:

```html
    <span id="tap-label">Tap to play</span>
  </div>
  <button id="unmute-btn" class="hidden" type="button" aria-label="Tap for sound">
    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/></svg>
    <span id="unmute-label">Tap for sound</span>
  </button>
```

(The static English text stays as a no-JS fallback; the script overwrites it immediately below.)

- [ ] **Step 2: Set localized text for the overlay labels**

Find:

```js
const tapOverlay = document.getElementById("tap-overlay");
const unmuteBtn = document.getElementById("unmute-btn");
```

Replace with:

```js
const tapOverlay = document.getElementById("tap-overlay");
const unmuteBtn = document.getElementById("unmute-btn");

document.getElementById("tap-label").textContent = t("tapToPlay");
document.getElementById("unmute-label").textContent = t("tapForSound");
unmuteBtn.setAttribute("aria-label", t("tapForSound"));
```

- [ ] **Step 3: Localize the missing-`?stream=`, loading, reconnecting, and unavailable messages**

Find:

```js
if (!stream) {
  document.body.innerHTML = '<p style="color:#fff;font-family:sans-serif;text-align:center;margin-top:2rem">Missing required <code>?stream=</code> parameter.</p>';
  throw new Error("Missing stream parameter");
}
```

Replace with:

```js
if (!stream) {
  document.body.innerHTML = `<p style="color:#fff;font-family:sans-serif;text-align:center;margin-top:2rem">${t("missingStreamParam")}</p>`;
  throw new Error("Missing stream parameter");
}
```

Find:

```js
  setStatus("Loading stream…");
```

Replace with:

```js
  setStatus(t("loading"));
```

Find:

```js
        setStatus("Stream unavailable right now.", true);
```

Replace with:

```js
        setStatus(t("unavailable"), true);
```

Find:

```js
      setStatus("Reconnecting stream…");
```

Replace with:

```js
      setStatus(t("reconnecting"));
```

- [ ] **Step 4: Manually verify in a real browser**

This script has no PHPUnit/JS test harness (spec: "Testing" — verification is manual). Chrome's
`--lang` launch flag does **not** reliably change `navigator.language` on macOS (confirmed —
see [[macos-chrome-lang-flag-unreliable]] memory), so force a Korean locale via
`chrome://settings/languages` instead: add 한국어 (Korean) and drag it to the top of the list, then
reload. That reordering is what actually changes `navigator.language`/`navigator.languages`.

Load `http://filament-mediamtx.test/play?stream=<a-real-or-test-stream-path>`.

Expected: the loader shows "스트림 불러오는 중…" while connecting. If no stream is live, it should
eventually show "현재 스트림을 사용할 수 없습니다." after retries exhaust. Load the same URL in a
move English back to the top of `chrome://settings/languages` and reload to confirm it goes back to
English text ("Loading stream…" / "Stream unavailable right now."). Also load
`http://filament-mediamtx.test/play` (no `?stream=`) in both language orderings and confirm the
missing-parameter message is localized correctly in each.

If a stream is live, also confirm the tap-to-play overlay (if autoplay is blocked) or the
Safari-only unmute button reads the localized label — Safari's unmute button specifically requires
testing in real Safari (desktop or iOS), since `IS_SAFARI` gates its visibility.

- [ ] **Step 5: Commit**

```bash
git add resources/views/player.blade.php
git commit -m "feat(player): localize loader status text and overlay labels"
```

---

### Task 3: Classify thrown token errors and localize the composed load-error message

**Files:**
- Modify: `resources/views/player.blade.php` (`fetchToken()` and `initPlayer()`)

**Interfaces:**
- Consumes: `LANG`, `t(key, vars?)` from Task 1.
- Produces: `err.code` (`"TOKEN_DENIED"` | `"NO_TOKEN"` | undefined) on errors thrown by
  `fetchToken()`; `reasonFor(err)` helper consumed only within `initPlayer()`'s catch block.

- [ ] **Step 1: Attach `.code`/`.status` to the two errors `fetchToken()` throws deliberately**

Find:

```js
      const retryable = res.status >= 500 || res.status === 422;
      if (retryable && attempt < 2) continue;
      if (!res.ok) throw new Error(`Token request denied (${res.status})`);

      const data = await res.json();
      if (!data.token) throw new Error("JWT not received");
```

Replace with:

```js
      const retryable = res.status >= 500 || res.status === 422;
      if (retryable && attempt < 2) continue;
      if (!res.ok) {
        const err = new Error(`Token request denied (${res.status})`);
        err.code = "TOKEN_DENIED";
        err.status = res.status;
        throw err;
      }

      const data = await res.json();
      if (!data.token) {
        const err = new Error("JWT not received");
        err.code = "NO_TOKEN";
        throw err;
      }
```

- [ ] **Step 2: Switch the retry-gate check from string matching to the new `.code`**

Find:

```js
      // Don't retry auth denials
      if (err.message.startsWith("Token request denied") || attempt === 2) throw err;
```

Replace with:

```js
      // Don't retry auth denials
      if (err.code === "TOKEN_DENIED" || attempt === 2) throw err;
```

- [ ] **Step 3: Build the localized composed error message in `initPlayer()`**

Find:

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
    setStatus(t("loadErrorPrefix") + reasonFor(err), true);
    showLoader(false);
    starting = false;
    return;
  }
```

- [ ] **Step 4: Add the `reasonFor()` helper**

Find:

```js
function setStatus(msg, isError = false) {
  statusEl.textContent = msg;
  statusEl.classList.toggle("error", isError);
}
```

Replace with:

```js
function setStatus(msg, isError = false) {
  statusEl.textContent = msg;
  statusEl.classList.toggle("error", isError);
}

// err.code is set for the two deliberate failure modes in fetchToken(); anything
// uncoded (e.g. a browser-native network error) gets a generic translated reason
// instead of leaking untranslated raw error text to the viewer.
function reasonFor(err) {
  if (err.code === "TOKEN_DENIED") return t("reasonTokenDenied", { status: err.status });
  if (err.code === "NO_TOKEN") return t("reasonNoToken");
  return t("reasonGeneric");
}
```

- [ ] **Step 5: Manually verify both classified paths render correctly**

No automated harness covers this path either. Force each failure mode and confirm the localized
text using the same `chrome://settings/languages` reordering trick as Task 2 Step 4:

1. **`TOKEN_DENIED`**: temporarily block your own IP in the `blocked_ips` table (or add a
   non-matching `allowed_referrers` rule) so `POST /api/read` returns 403, then load `/play?stream=<path>`.
   Expected English: `Couldn't load stream: access denied (403)`. Expected Korean:
   `스트림을 불러올 수 없습니다: 접근이 거부되었습니다 (403)`. Revert the blocklist/referrer change
   afterward.
2. **Generic/uncoded failure**: temporarily stop the app's web server (or point `JWT` config
   somewhere invalid) so the `fetch()` in `fetchToken()` fails at the network level, then load the
   page. Expected English: `Couldn't load stream: connection error`. Expected Korean:
   `스트림을 불러올 수 없습니다: 연결 오류`. Restore the server/config afterward.

- [ ] **Step 6: Commit**

```bash
git add resources/views/player.blade.php
git commit -m "feat(player): classify token errors and localize composed error message"
```

---

### Task 4: Documentation

**Files:**
- Create: `docs/player-korean-i18n.md`
- Modify: `README.md` (only if it documents `/play` player behavior/query params — check first)

- [ ] **Step 1: Write the doc following the repo's Symptom → Root cause → Fix → Verify → Deploy convention**

Create `docs/player-korean-i18n.md`:

```markdown
# Player Korean Localization

## Symptom

The `/play` WHEP player (`resources/views/player.blade.php`) always rendered loader status text,
error messages, and the tap/unmute overlay labels in English, regardless of the viewer's language.

## Root Cause

No localization existed for this view — all viewer-facing strings were hardcoded English literals
in the inline `<script>` block, and the raw composed error message
(`` `Couldn't load stream: ${err.message}` ``) embedded whatever raw JS error text was thrown,
which is not translatable by content matching.

## Fix

Added a client-side-only `LANG` detection (`navigator.languages`/`navigator.language`, falling
back to `"en"` for anything not recognizably Korean), an inline `MESSAGES` dictionary (`en` + `ko`),
and a `t(key, vars)` lookup helper — all inside `player.blade.php`'s existing script, no new files
or build changes. The two errors `fetchToken()` throws deliberately (`TOKEN_DENIED` on a non-OK
response, `NO_TOKEN` when the response body lacks a token) now carry a `.code` so the composed
error message can be classified and localized instead of translating raw error text; any uncoded
failure (e.g. a network-level fetch error) falls back to a generic translated reason. The loader
status text, missing-`?stream=` message, and tap-to-play/tap-for-sound overlay labels are all
localized the same way.

Detection is entirely client-side — no route, controller, or query-parameter changes. `/moq` and
other views are untouched.

## Verify

Chrome's `--lang` launch flag does **not** reliably change `navigator.language` on macOS. Instead,
in `chrome://settings/languages`, add 한국어 (Korean) and drag it to the top of the list, then
reload the player page — that reordering is what actually drives `navigator.language`. Move English
back to the top afterward to revert.

Confirm the loader/status/error text renders in Korean with Korean at the top of that list, and in
English with English at the top, for: normal load, missing `?stream=`, a forced 403 (blocked IP or
rejected referrer), and a forced network-level failure.

## Deploy

No migration, env var, or config change. Ships with the next regular deploy of this branch.
```

- [ ] **Step 2: Check whether README.md documents `/play` and update if so**

```bash
grep -n "play\b\|WHEP player\|player.blade" README.md
```

If matches reference the `/play` player's behavior (not just its existence as a route), add a
one-line mention that status/error text is localized to Korean based on browser language,
consistent with whatever style the surrounding section already uses. If `README.md` doesn't
describe player-side behavior at this level of detail, skip this step — don't invent a new section.

- [ ] **Step 3: Commit**

```bash
git add docs/player-korean-i18n.md README.md
git commit -m "docs(player): document Korean localization of status/error text"
```

(Omit `README.md` from the `git add` if Step 2 concluded no change was needed.)
