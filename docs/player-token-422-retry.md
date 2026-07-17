# Player: retry token fetch on transient 422

## Symptom

A third-party embed (`<iframe loading="lazy" src="https://studio.jrta.online/play?stream=cgtable&muted=false&controls=true">`)
occasionally failed on first load with:

```
Couldn't load stream: Token request denied (422)
```

Reloading the page (same iframe URL, unchanged) always fixed it immediately.

## Root cause

In [app/Http/Controllers/Api/ReadTokenController.php](../app/Http/Controllers/Api/ReadTokenController.php),
`POST /api/read` returns `422` from exactly one place: `$request->validate()`
failing the `path` field (`required|string|max:255`). `source` is `nullable`
and always a plain string from `PlayerController`, so it can't trigger this.
Every other denial in that controller (blocked IP, bad referer) returns `403`,
never `422` — so a `422` here can never be a legitimate auth/security denial.

The player computes `path` from `window.location.search` once at script load
([resources/views/player.blade.php:243-244](../resources/views/player.blade.php#L243-L244)),
and bails out entirely with a "Missing required `?stream=`" message *before*
ever calling `fetchToken()` if that value were empty
([player.blade.php:260-262](../resources/views/player.blade.php#L260-L262)).
The failing loads reached the token-fetch/WHEP flow, so `stream` was non-empty
client-side — meaning the request body left the browser correctly. The `path`
must have arrived empty/invalid server-side instead, most likely from
something transient between the browser and Laravel (a proxy or edge layer in
front of the embed domain mangling the POST body on a lazy-loaded iframe's
first cross-origin request). `fetchToken()`'s retry loop only retried on
`>= 500`, so this single transient `422` was thrown immediately with no retry,
matching the "works after a manual refresh" behavior exactly — a refresh is
just a fresh attempt.

## Fix

In `fetchToken()` ([player.blade.php:415-456](../resources/views/player.blade.php#L415-L456)),
extended the existing retry condition to also cover `422`:

```js
const retryable = res.status >= 500 || res.status === 422;
if (retryable && attempt < 2) continue;
```

This is safe specifically because `422` on this endpoint can only mean
"path was rejected as empty/invalid" — never a real auth denial (those are
`403` and are still thrown immediately, unretried, via the `catch` block's
`err.message.startsWith("Token request denied")` check). The existing 3-attempt
cap and `1500ms * attempt` backoff apply unchanged.

## Verify

1. Temporarily force a `422` (e.g. add a throwaway `abort(422)` early in
   `ReadTokenController::__invoke`, or block `path` via a bad query string) and
   confirm the player's Network tab shows up to 3 `POST /api/read` attempts
   (with backoff) before it gives up and shows the error — instead of failing
   on the first attempt.
2. Revert the temporary change and confirm normal playback still fetches the
   token on the first attempt.

## Deploy

Frontend-only change to an inline `<script>` in the Blade view. No asset
rebuild required. `git push` to the server; run `php artisan view:clear` if
compiled views are cached.
