# JWKS endpoint is public (IP allowlist removed)

## Symptom

`GET /.well-known/jwks.json` returned 403 for any caller not listed in `MEDIAMTX_ALLOWED_IPS`. If the IP MediaMTX
used to reach the app changed (proxy, NAT, Cloudflare, IPv6 vs IPv4) or the variable was unset, the JWKS fetch failed
and MediaMTX rejected every JWT with a generic "authentication error".

## Root cause

The route was wrapped in the `AllowMediamtxOnly` middleware (alias `jwks.ip`), which was fail-closed: an empty or
stale allowlist denied everyone, including MediaMTX.

## Fix

The allowlist was removed entirely:

- `routes/web.php` — the JWKS route no longer has middleware.
- `bootstrap/app.php` — the `jwks.ip` alias was removed.
- `app/Http/Middleware/AllowMediamtxOnly.php` — deleted (it had no other users).
- `config/services.php` — the `mediamtx.allowed_ips` key was removed; `MEDIAMTX_ALLOWED_IPS` is no longer read.

This is safe because a JWKS document holds only the **public** RS256 key. Publishing it is the standard practice
(OIDC providers do it). It lets anyone verify tokens, but nobody can mint them. The private key never leaves
`storage/app/jwt/private.pem`.

## Verify

```bash
php artisan route:list --path=.well-known          # no middleware listed on the jwks route
curl -s https://<app-host>/.well-known/jwks.json    # 200 with {"keys":[...]} from any IP
```

Then play a stream (`/play`) and confirm MediaMTX accepts the read token.

## Deploy

1. Pull the change on the server and clear the caches as `www-data`:
   `sudo -u www-data php artisan optimize:clear && sudo -u www-data php artisan optimize`
2. Optionally delete `MEDIAMTX_ALLOWED_IPS` from `.env`. It is harmless if you leave it, because nothing reads it.
