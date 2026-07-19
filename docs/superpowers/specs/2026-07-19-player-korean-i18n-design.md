# Player Korean Localization — Design Spec
**Date:** 2026-07-19
**Branch:** production

---

## Overview

All viewer-facing status/error/overlay text in `resources/views/player.blade.php` (the `/play`
WHEP player) currently renders in hardcoded English. This adds Korean translations for that text,
selected automatically based on the viewer's browser language — no query parameter, route, or
controller change involved. Detection always falls back to English when the browser isn't
recognizably Korean.

This is scoped to `player.blade.php` only. `MonitorPlayerController`'s reuse of the same view picks
up the same behavior for free (same template, same script). The `/moq` player is a separate view
and is out of scope.

## Language Detection

Computed once, near the top of the existing inline `<script>` block, before the `?stream=` guard
so that error message can be localized too:

```js
const LANG = (navigator.languages || [navigator.language || ''])
  .some(l => l.toLowerCase().startsWith('ko')) ? 'ko' : 'en';
```

Anything not recognizably Korean (`ko`, `ko-KR`, etc.) resolves to `'en'`. English is always the
fallback — there is no third language and no error state in detection itself.

## Translation Mechanism

A plain inline dictionary plus a lookup helper, added directly in `player.blade.php`'s script —
no new files, no Vite/build changes, consistent with this player being a single self-contained
Blade view:

```js
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
```

## String-by-String Changes

| Current (English literal) | Location | Replacement |
|---|---|---|
| `'<p style="...">Missing required <code>?stream=</code> parameter.</p>'` | `!stream` guard | `t('missingStreamParam')`, wrapped in the same markup |
| `setStatus("Loading stream…")` | `initPlayer()` | `setStatus(t('loading'))` |
| `` setStatus(`Couldn't load stream: ${err.message}`, true) `` | `initPlayer()` catch | `setStatus(t('loadErrorPrefix') + reasonFor(err), true)` — see below |
| `setStatus("Stream unavailable right now.", true)` | `onError` | `setStatus(t('unavailable'), true)` |
| `setStatus("Reconnecting stream…")` | `onError` | `setStatus(t('reconnecting'))` |
| `<span>Tap to play</span>` | tap-overlay markup | give the `<span>` an `id="tap-label"`; set `textContent = t('tapToPlay')` in script |
| `aria-label="Tap for sound"` + `<span>Tap for sound</span>` | unmute button markup | give the `<span>` an `id="unmute-label"`; script sets both the span's `textContent` and the button's `aria-label` to `t('tapForSound')` |

### Handling the composed load-error message

`err.message` today is raw JS text ("Token request denied (403)", "JWT not received", or a
browser-native network error string) and can't be reliably translated by string content. Instead,
`fetchToken()` attaches a `.code` (and `.status` where relevant) to the two errors it throws
deliberately:

```js
// on non-ok response:
const err = new Error(`Token request denied (${res.status})`);
err.code = "TOKEN_DENIED";
err.status = res.status;
throw err;

// on missing token in response body:
const err = new Error("JWT not received");
err.code = "NO_TOKEN";
throw err;
```

The retry-gate check in `fetchToken`'s catch block (`err.message.startsWith("Token request denied")`)
switches to `err.code === "TOKEN_DENIED"` — a direct consequence of moving the classification off
the message string, not an unrelated refactor.

`initPlayer()`'s catch block builds the localized reason from the code:

```js
function reasonFor(err) {
  if (err.code === "TOKEN_DENIED") return t("reasonTokenDenied", { status: err.status });
  if (err.code === "NO_TOKEN") return t("reasonNoToken");
  return t("reasonGeneric");
}
```

Any uncoded error (rare network-level failures, e.g. a browser's own "Failed to fetch") falls back
to the generic translated phrase rather than leaking untranslated English fragments into a Korean
viewer's screen. `console.error("WebRTC error:", err)` (already present) keeps the raw message
available for debugging regardless of language.

## Out of Scope

- The `<title>WebRTC Player</title>` tag — not shown to viewers, not a status/error message.
- `/moq` player and any other view — separate template, not touched.
- Any server-side/controller change — detection and rendering are entirely client-side JS, matching
  the existing pattern where `?stream=`, `?autoplay=`, `?muted=`, etc. are all read client-side too.
- A general-purpose i18n system (Laravel `lang/` files, `__()`) — this codebase has none today, and
  introducing one for a single view's ~10 strings would be disproportionate.

## Testing

No existing test coverage touches `player.blade.php`'s inline script (it's plain JS embedded in a
Blade view, not exercised by PHPUnit). Verification is manual: load `/play?stream=<test>` with the
browser's language set to Korean and to English, and exercise each status/error path (normal load,
missing `?stream=`, forced token failure, forced reconnect) to confirm the right language renders
in each case.
