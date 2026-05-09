# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A **Filament v3 admin panel** (Laravel 12, PHP 8.2+) for managing a [MediaMTX](https://github.com/bluenviron/mediamtx) media server. The app issues RS256 JWTs that MediaMTX uses to authorize streaming clients, and provides an admin UI for managing publish tokens, viewer access control, live sessions, and security audit logs.

## Commands

```bash
# First-time setup (install deps, generate key, migrate, build assets)
composer run setup

# Start full dev environment (HTTP server + queue worker + log tail + Vite)
composer run dev

# Run all tests
composer run test

# Run a single test file or filter
php artisan test --filter MyTestClass
php artisan test tests/Feature/SomeTest.php

# Code style (Laravel Pint)
./vendor/bin/pint

# Build frontend assets
npm run build

# Prune old data (run via scheduler or manually)
php artisan activity-log:prune --days=30
php artisan viewer-requests:prune --days=7
```

Tests use SQLite in-memory (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`). The test env is configured in `phpunit.xml`.

## Architecture

### JWT / MediaMTX Integration

The core purpose of this app is acting as a **JWT authorization server** for MediaMTX.

- **RS256 keypair** lives at `storage/app/jwt/private.pem` and `storage/app/jwt/public.pem` (paths set via `JWT_PRIVATE_KEY_PATH` / `JWT_PUBLIC_KEY_PATH`).
- **JWKS endpoint** at `GET /.well-known/jwks.json` — MediaMTX polls this to verify JWTs. Protected by `AllowMediamtxOnly` middleware (IP allowlist via `MEDIAMTX_ALLOWED_IPS`).
- **Publish tokens**: Long-lived (~10-year) JWTs issued by `MediamtxJwtIssuer`, stored in the DB, copied to stream encoders. Managed via the `PublishTokenResource` Filament resource.
- **Read tokens**: Short-lived JWTs (default 60s, `JWT_TTL_SECONDS`) issued by `POST /api/read`. Called by embedded players. Gated by IP blocklist + referrer allowlist before issuing.

### Read Token Flow (`POST /api/read`)

`ReadTokenController` enforces two gates before issuing a JWT:
1. **IP blocklist** — checks `blocked_ips` table.
2. **Referrer allowlist** — checks `allowed_referrers` table; patterns support wildcards (e.g. `https://*.example.com`) or regex. Strictly requires a non-empty `Referer` header.

Viewer requests are logged to `viewer_requests` with UA parsing (OS/browser/device via `jenssegers/agent`), throttled by a cache lock (`VIEWER_LOG_THROTTLE_SECONDS`, default 10s).

### Filament Admin Panel

Panel root is `/` (no path prefix). Login uses **username** (email column was dropped; use `username` field).

**Resources** (auto-discovered from `app/Filament/Resources/`):
- `PublishTokenResource` — manage streaming publish credentials; copy/rotate token actions
- `AllowedReferrerResource` — referrer whitelist for the read token gate
- `BlockedIpResource` — IP blocklist management
- `UserResource` — user management (username-only auth)
- `SecurityEventResource` — read-only view of `security` activity log channel
- `ActivityResource` — full activity log viewer

**Pages:**
- `Sessions` — live WebRTC session table, refreshed from MediaMTX API on mount; supports kick and block-IP actions inline

**Dashboard Widgets** (analytics cached 60s via `DashboardCache`):
- `SessionsOverview`, `OsBreakdownChart`, `BrowserBreakdownChart`, `DeviceBreakdownChart`, `TopIpsTable`

### RBAC (Spatie Permissions)

Three roles seeded by `RolesSeeder`:
- `super-admin` — full access including destructive actions (delete, user management)
- `moderator` — manage tokens, sessions, referrers, block IPs; no delete
- `monitoring` — read-only (copy tokens, view dashboards)

Access gates are inline `visible()` / `canAccess()` / `canViewAny()` callbacks on Filament resources and actions, not policy classes.

### Services

| Service | Purpose |
|---|---|
| `MediamtxApi` | HTTP client wrapping the MediaMTX v3 REST API (`MEDIAMTX_API_BASE_URL`). Currently: list/kick WebRTC sessions. |
| `MediamtxJwtIssuer` | Signs publish JWTs (RS256, no-expiry). |
| `MediamtxSessionRefresher` | Syncs live WebRTC sessions from MediaMTX into `mediamtx_sessions` table; deletes rows not seen within 20s. |
| `DashboardAnalytics` | Aggregates `viewer_requests` for OS/browser/device breakdown charts. |
| `DashboardCache` | Stable cache key generation + `cache()->remember()` wrapper for dashboard queries. |

### Key ENV Variables

```
JWT_PRIVATE_KEY_PATH      # Path to RS256 private key (relative to project root)
JWT_PUBLIC_KEY_PATH       # Path to RS256 public key
JWT_KID                   # Key ID sent in JWT header and JWKS
JWT_ISSUER                # `iss` claim (defaults to APP_URL)
JWT_TTL_SECONDS           # Read token lifetime in seconds (default 60)

MEDIAMTX_API_BASE_URL     # MediaMTX management API base URL (default http://127.0.0.1:9997)
MEDIAMTX_API_USER         # Basic auth user for MediaMTX API (optional)
MEDIAMTX_API_PASS         # Basic auth pass for MediaMTX API (optional)
MEDIAMTX_API_TIMEOUT      # HTTP timeout in seconds (default 3)
MEDIAMTX_ALLOWED_IPS      # Comma-separated IPs allowed to access /.well-known/jwks.json

VIEWER_LOG_THROTTLE_SECONDS   # Dedup window for viewer_requests logging (default 10)
VIEWER_LOG_THROTTLE_MODE      # Fingerprint mode: ip_path_ua | ip_path | ip_path_ref
```

### Database

MySQL in production; all tables use standard Laravel migrations. High-traffic tables (`viewer_requests`, `activity_log`) have compound performance indexes added in separate migration files. Queue, cache, and sessions use the database driver.

### Frontend

Tailwind CSS v4 via `@tailwindcss/vite`. A `clipboard-listener` Blade view is injected into the panel body end to handle the `clipboard-copy` Livewire browser event dispatched by the copy-token action.
