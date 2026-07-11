# MediaMTX Admin

A **Filament v5 admin panel** (Laravel 13, Livewire 4, PHP 8.3+) for operating a [MediaMTX](https://github.com/bluenviron/mediamtx)
media server. The app acts as an **RS256 JWT authorization server** for MediaMTX and provides an admin UI for managing
publish credentials, viewer access control, live sessions, an embeddable player, security audit logs, and real‑time
Telegram alerts.

---

## Table of Contents

- [What it does](#what-it-does)
- [Architecture](#architecture)
  - [JWT / MediaMTX integration](#jwt--mediamtx-integration)
  - [Read‑token flow (`POST /api/read`)](#readtoken-flow-post-apiread)
  - [Embeddable player (`GET /play`)](#embeddable-player-get-play)
  - [Media‑over‑QUIC player (`GET /moq`)](#media-over-quic-player-get-moq)
  - [Session alerts (event‑driven)](#session-alerts-eventdriven)
  - [Telegram security alerts](#telegram-security-alerts)
  - [Admin panel](#admin-panel)
  - [RBAC](#rbac)
  - [Services](#services)
- [Routes](#routes)
- [Environment variables](#environment-variables)
- [Local development](#local-development)
- [Production deployment (Ubuntu + Apache + PHP 8.4 + Node 22 + Redis + MySQL + MediaMTX)](#production-deployment)
- [Updating an existing deployment](#updating-an-existing-deployment)
- [Maintenance & scheduled tasks](#maintenance--scheduled-tasks)

---

## What it does

- **Issues RS256 JWTs** that MediaMTX verifies (via a JWKS endpoint) to authorize streaming clients.
- **Publish tokens** — long‑lived (~10‑year) JWTs copied into stream encoders/cameras.
- **Read tokens** — short‑lived (default 60 s) JWTs minted on demand for embedded players, gated by IP blocklist and
  referrer allowlist.
- **Embeddable WebRTC player** at `/play`, protected by an IP whitelist/blacklist and iframe‑embedding rules.
- **Media‑over‑QUIC player** at `/moq` — the same streams over MoQ/WebTransport for lower latency, reusing the same access gates.
- **Live session monitoring** — a Sessions page synced on demand from the MediaMTX REST API, listing **all
  protocols** (WebRTC, MoQ, RTMP, RTSP, SRT), with play (publish sessions only), kick (any protocol except HLS,
  which has no kick endpoint), and block‑IP actions.
- **Analytics dashboard** — OS / browser / device / top‑IP breakdowns aggregated from viewer requests.
- **Security & activity audit logs** via Spatie Activitylog.
- **Real‑time Telegram alerts** when a stream or viewer starts/stops, driven by MediaMTX run‑hooks (no polling);
  plus a curated, configurable set of security alerts (kicks, IP blocks/unblocks, denied reads) forwarded from the
  activity log.
- **Role‑based access control** (super‑admin / moderator / monitoring) via Spatie Permission.

---

## Architecture

### JWT / MediaMTX integration

The core purpose of this app is acting as a **JWT authorization server** for MediaMTX.

- **RS256 keypair** lives at `storage/app/jwt/private.pem` and `storage/app/jwt/public.pem`
  (paths set via `JWT_PRIVATE_KEY_PATH` / `JWT_PUBLIC_KEY_PATH`). The private key is cached as a singleton to avoid
  per‑request disk reads.
- **JWKS endpoint** at `GET /.well-known/jwks.json` — MediaMTX polls this (once) to fetch the public key used to verify
  JWTs. Protected by the `AllowMediamtxOnly` middleware (IP allowlist via `MEDIAMTX_ALLOWED_IPS`); the response is cached.
- **Publish tokens** — long‑lived JWTs issued by `MediamtxJwtIssuer`, stored in the DB, copied into stream encoders.
  Managed via `PublishTokenResource` (copy / rotate actions).
- **Read tokens** — short‑lived JWTs (`JWT_TTL_SECONDS`, default 60 s) issued by `POST /api/read`, called by embedded
  players. Gated by IP blocklist + referrer allowlist before issuing.

The JWT `mediamtx_permissions` claim is what MediaMTX reads (`authJWTClaimKey: mediamtx_permissions`).

### Read‑token flow (`POST /api/read`)

`ReadTokenController` enforces two gates before issuing a JWT:

1. **IP blocklist** — checks the `blocked_ips` table (cached ~30 s).
2. **Referrer allowlist** — checks `allowed_referrers`; patterns support wildcards (e.g. `https://*.example.com`) or
   regex. A non‑empty `Referer` header is strictly required.

Client IP is resolved by `ClientIpResolver`, which only trusts forwarding headers (`CF-Connecting-IP` /
`X-Forwarded-For`) from proxies listed in `MEDIAMTX_TRUSTED_PROXIES` (defaults to Cloudflare ranges) — this closes
IP‑spoofing bypasses. The route is rate‑limited (`throttle:token-read`).

Viewer requests are logged to `viewer_requests` with UA parsing (OS / browser / device via `jenssegers/agent`),
throttled by a cache lock (`VIEWER_LOG_THROTTLE_SECONDS`, default 10 s; fingerprint via `VIEWER_LOG_THROTTLE_MODE`).

### Embeddable player (`GET /play`)

`PlayerController` renders a Blade WebRTC player, protected by `PlayerAccessMiddleware`:

- **Whitelist / blacklist mode** — toggled on the `PlayerSettings` page; rules live in `player_ip_rules`.
- **Iframe embedding protection** — controls which origins may embed the player.
- **Direct playback** option — `PlayerSettings` controls whether playback starts automatically; `ReadTokenController`
  respects this.

The player fetches a short‑lived read token from `POST /api/read` and connects to MediaMTX over WebRTC
(`MEDIAMTX_STREAM_BASE_URL`).

### Media‑over‑QUIC player (`GET /moq`)

`MoqPlayerController` renders a second, parallel player that plays the same streams over
**Media‑over‑QUIC (MoQ / WebTransport)** for lower latency (~200–300 ms). It reuses the `/play`
access model exactly — same `player.access` IP gate, same `allow_direct_playback`/embed rules, the
same read JWT from `POST /api/read` (unchanged). It uses a vendored copy of MediaMTX's own browser
reader (`resources/js/moq-reader.js`, WebTransport + WebCodecs) and passes the JWT as the MoQ
AUTH_TOKEN parameter (WebTransport can't set an `Authorization` header).

Unlike WHEP, the MoQ **media** rides a **direct UDP** path to MediaMTX (`MEDIAMTX_MOQ_BASE_URL`,
default port 8892) — WebTransport is HTTP/3/QUIC and can't be reverse‑proxied by Apache or
Cloudflare — and MediaMTX must present a real TLS cert for the hostname. Browser support is narrower
(Chrome/Edge 110+, Firefox 130+, Safari 26.4+); unsupported browsers get a fallback link to `/play`.
WHIP ingest and WHEP `/play` are untouched. See [docs/moq-player.md](docs/moq-player.md) for server
config, cert, and network exposure.

### Session alerts (event‑driven)

Alerts are **push‑based**, not polled. MediaMTX run‑hooks `POST` to `/api/mediamtx/event`
(`MediamtxEventController`, protected by `VerifyMediamtxWebhook` which checks `X-Webhook-Secret`). The controller maps
events to `SessionEvent` DTOs; `SessionNotifier` fans out via `TelegramNotifier`, which queues a `SendTelegramMessage`
job. Alerts include protocol type, real datetime, correlated referer/embedding origin and session id, with IP enriched
from the MediaMTX Control API and deduped by session id.

See [`docs/mediamtx-hooks.md`](docs/mediamtx-hooks.md) for the exact `mediamtx.yml` hook configuration.

### Telegram security alerts

A single listener, `SecurityAlertListener`, forwards a **curated, configurable allowlist** of
`security`‑channel activity‑log events to Telegram — session kicks, IP blocks/unblocks (from the Sessions page,
Top‑IPs widget, Security Events resource, or `BlockedIpResource`), and denied read‑token requests (blocked IP or
bad referrer). Routine CRUD (referrer/token add‑rotate‑delete) is excluded by default to avoid noise.

It is gated by **both** `TELEGRAM_ALERTS_ENABLED` and `TELEGRAM_SECURITY_ALERTS_ENABLED`, applies a per‑minute
flood cap (30/min) so denied‑read spikes under attack don't flood the chat, and reuses the same queued
`SendTelegramMessage` job as the session-start/stop alerts above. The event allowlist is configurable via
`TELEGRAM_SECURITY_EVENTS` (comma‑separated activity `description` values) without a code change. See
[`docs/sessions-telegram-improvements.md`](docs/sessions-telegram-improvements.md) for the full design, the
default allowlist, and how to extend it.

### Admin panel

Panel root is `/` (no path prefix). Login uses **username** (the email column was dropped).

**Resources** (auto‑discovered from `app/Filament/Resources/`):

| Resource | Purpose |
|---|---|
| `PublishTokenResource` | Manage streaming publish credentials; copy / rotate token actions |
| `AllowedReferrerResource` | Referrer whitelist for the read‑token gate |
| `BlockedIpResource` | IP blocklist management |
| `PlayerIpRuleResource` | Player IP whitelist/blacklist rules |
| `UserResource` | User management (username‑only auth) |
| `SecurityEventResource` | Read‑only view of the `security` activity‑log channel |
| `ActivityResource` | Full activity‑log viewer |

**Pages:**

- `Sessions` — live session table covering **all protocols** (WebRTC, MoQ, RTMP, RTSP, SRT), synced on demand from
  the MediaMTX API while open; supports kick (any protocol except HLS, which has no kick endpoint) and block‑IP
  actions inline.
- `PlayerSettings` — whitelist/blacklist mode toggle + direct‑playback option for the `/play` player.

**Dashboard widgets** (analytics cached 60 s via `DashboardCache`): `SessionsOverview`, `OsBreakdownChart`,
`BrowserBreakdownChart`, `DeviceBreakdownChart`, `TopIpsTable`.

### RBAC

Three roles, seeded by the database seeder:

- `super-admin` — full access, including destructive actions (delete, user management).
- `moderator` — manage tokens, sessions, referrers, block IPs; no delete.
- `monitoring` — read‑only (copy tokens, view dashboards).

Access gates are inline `visible()` / `canAccess()` / `canViewAny()` callbacks on resources and actions.

### Services

| Service | Purpose |
|---|---|
| `MediamtxApi` | HTTP client wrapping the MediaMTX v3 REST API — list / kick sessions for every protocol (WebRTC, MoQ, RTMP, RTSP, SRT) via `kick(string $type, string $id)`, resolve reader IPs. |
| `MediamtxJwtIssuer` | Signs publish & read JWTs (RS256). |
| `MediamtxSessionRefresher` | `syncSnapshot()` — merges live sessions from all five MediaMTX list endpoints into `mediamtx_sessions`, best‑effort per protocol. |
| `ClientIpResolver` | Resolves the real client IP, trusting forwarding headers only from configured proxies. |
| `ViewerRequestLogger` | Logs throttled viewer requests with UA parsing. |
| `DashboardAnalytics` | Aggregates `viewer_requests` for OS/browser/device/top‑IP breakdowns. |
| `SessionEvent` / `SessionNotifier` | DTO + fan‑out for stream/viewer start/stop alerts. |
| `SecurityAlertListener` | Forwards a curated, allowlisted set of `security`‑channel activity events to Telegram. |
| `TelegramAlert` (support) | Shared HTML‑escaping + per‑minute flood‑cap helpers used by `SessionNotifier` and `SecurityAlertListener`. |
| `TelegramNotifier` | Formats and dispatches Telegram alert messages (queued). |

---

## Routes

| Method | Path | Handler | Protection |
|---|---|---|---|
| GET | `/.well-known/jwks.json` | `JwksController` | `jwks.ip` (`AllowMediamtxOnly`) |
| GET | `/play` | `PlayerController` | `player.access` (`PlayerAccessMiddleware`) |
| GET | `/moq` | `MoqPlayerController` | `player.access` (`PlayerAccessMiddleware`) |
| POST | `/api/read` | `ReadTokenController` | `throttle:token-read` |
| POST | `/api/mediamtx/event` | `MediamtxEventController` | `mediamtx.webhook` (`VerifyMediamtxWebhook`) |
| — | `/` (admin panel) | Filament | Auth (username login) |

---

## Environment variables

```ini
# JWT / MediaMTX authorization
JWT_PRIVATE_KEY_PATH=storage/app/jwt/private.pem   # RS256 private key (relative to project root)
JWT_PUBLIC_KEY_PATH=storage/app/jwt/public.pem     # RS256 public key
JWT_KID=mediamtx-1                                 # Key ID sent in JWT header + JWKS
JWT_ISSUER=https://platform.example.com            # `iss` claim (defaults to APP_URL)
JWT_TTL_SECONDS=60                                 # Read‑token lifetime

# MediaMTX Control API
MEDIAMTX_API_BASE_URL=http://127.0.0.1:9997        # Management API base URL
MEDIAMTX_API_USER=                                 # Basic auth user (optional)
MEDIAMTX_API_PASS=                                 # Basic auth pass (optional)
MEDIAMTX_API_TIMEOUT=10                            # HTTP timeout (seconds)
MEDIAMTX_SESSION_STALE_SECONDS=20                  # Drop sessions not seen within this window
MEDIAMTX_STREAM_BASE_URL=https://stream.example.com# WebRTC base URL used by the /play player
MEDIAMTX_MOQ_BASE_URL=https://origin.example.com:8892 # MoQ/WebTransport endpoint for the /moq player (hostname + UDP port)
MEDIAMTX_ALLOWED_IPS=127.0.0.1,::1                 # IPs allowed to hit /.well-known/jwks.json
MEDIAMTX_TRUSTED_PROXIES=...                        # Proxy IPs/CIDRs whose forwarding headers are trusted (CF ranges, or "*")

# Viewer request logging
VIEWER_LOG_THROTTLE_SECONDS=10
VIEWER_LOG_THROTTLE_MODE=ip_path_ua                # ip_path_ua | ip_path | ip_path_ref

# Telegram alerts (event‑driven via MediaMTX hooks)
TELEGRAM_ALERTS_ENABLED=true
TELEGRAM_BOT_TOKEN=                                 # from @BotFather
TELEGRAM_CHAT_ID=                                   # chat or channel id
TELEGRAM_TIMEOUT=5
TELEGRAM_SECURITY_ALERTS_ENABLED=true               # forward curated `security`-channel activity to Telegram
TELEGRAM_SECURITY_EVENTS=session.kicked,ip.blocked.from_session,ip.blocked,ip.blocked.from_security_events,ip.blocked.from_top_ips,ip.unblocked,token.read.denied.blocked_ip,token.read.denied.bad_referer
MEDIAMTX_WEBHOOK_SECRET=                            # shared secret for X-Webhook-Secret header
```

> **Co‑located deployment note:** when Laravel and MediaMTX share one VM behind NAT, point MediaMTX's `authJWTJWKS`
> and the run‑hook URLs at `127.0.0.1` (or the public domain if hairpin NAT works) to avoid round‑tripping through the
> public IP.

---

## Local development

Requirements: PHP 8.3+, Composer, Node 18+ (22 recommended), and a database (SQLite works for dev).

```bash
# First‑time setup: install deps, copy .env, generate APP_KEY, migrate, build assets
composer run setup

# Generate the RS256 JWT keypair (not created by the setup script)
mkdir -p storage/app/jwt
openssl genpkey -algorithm RSA -out storage/app/jwt/private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -in storage/app/jwt/private.pem -pubout -out storage/app/jwt/public.pem

# Seed roles + default admin (username: admin / password: password — change immediately)
php artisan db:seed

# Start the full dev environment (HTTP server + queue worker + log tail + Vite)
composer run dev
```

Other useful commands:

```bash
composer run test                       # run all tests (SQLite in‑memory)
php artisan test --filter MyTestClass   # single test / filter
./vendor/bin/pint                       # code style
npm run build                           # build frontend assets
```

---

## Production deployment

Target stack: **Ubuntu 22.04/24.04 · Apache 2.4 · PHP 8.4 (+extensions) · Node 22 · Redis · MySQL 8 · MediaMTX**.

The commands below assume a fresh server and the app deployed to `/var/www/mediamtx-admin`, served from
`platform.example.com`, with MediaMTX on the same host serving `stream.example.com`.

### 1. System packages

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y software-properties-common ca-certificates curl git unzip apache2 mysql-server redis-server
```

### 2. PHP 8.4 + extensions

```bash
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update

sudo apt install -y \
  php8.4 php8.4-cli php8.4-common \
  libapache2-mod-php8.4 \
  php8.4-mysql php8.4-redis \
  php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip \
  php8.4-gd php8.4-intl php8.4-bcmath \
  php8.4-opcache php8.4-readline

php -v   # confirm 8.4
```

> `php8.4-curl` + OpenSSL cover JWT signing (`firebase/php-jwt`) and outbound MediaMTX/Telegram calls; `gd` and `intl`
> are required by Filament; `redis` enables the phpredis cache/queue/session drivers.

### 3. Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### 4. Node 22

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
node -v   # confirm v22.x
```

### 5. MySQL database & user

```bash
sudo mysql <<'SQL'
CREATE DATABASE mediamtx_admin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'admin'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON mediamtx_admin.* TO 'admin'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
```

### 6. Redis

The package ships running. Make it durable and start on boot:

```bash
sudo systemctl enable --now redis-server
redis-cli ping   # → PONG
```

### 7. Deploy the application

```bash
sudo mkdir -p /var/www
sudo chown -R "$USER" /var/www
cd /var/www
git clone <your-repo-url> mediamtx-admin
cd mediamtx-admin

# Production dependencies only
composer install --no-dev --optimize-autoloader
npm ci
npm run build

cp .env.example .env
```

### 8. Configure `.env`

Edit `/var/www/mediamtx-admin/.env`:

```ini
APP_NAME="MediaMTX Admin"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://platform.example.com

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mediamtx_admin
DB_USERNAME=admin
DB_PASSWORD=CHANGE_ME_STRONG_PASSWORD

# Use Redis for cache, sessions and queue in production
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# JWT / MediaMTX / Telegram — see the Environment variables section above
JWT_ISSUER=https://platform.example.com
MEDIAMTX_API_BASE_URL=http://127.0.0.1:9997
MEDIAMTX_ALLOWED_IPS=127.0.0.1,::1
MEDIAMTX_STREAM_BASE_URL=https://stream.example.com
TELEGRAM_ALERTS_ENABLED=true
TELEGRAM_BOT_TOKEN=...
TELEGRAM_CHAT_ID=...
MEDIAMTX_WEBHOOK_SECRET=$(openssl rand -hex 32)
```

> If you keep the database drivers from `.env.example` instead of Redis, the `cache`, `sessions` and `jobs` tables are
> created by the migrations — no extra step needed.

### 9. App key, JWT keypair, migrate, seed

```bash
cd /var/www/mediamtx-admin

php artisan key:generate

# RS256 keypair MediaMTX uses to verify JWTs
mkdir -p storage/app/jwt
openssl genpkey -algorithm RSA -out storage/app/jwt/private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -in storage/app/jwt/private.pem -pubout -out storage/app/jwt/public.pem

php artisan migrate --force
php artisan db:seed --force   # roles + default admin (admin / password)
```

**Log in and change the default `admin` password immediately.**

### 10. Permissions & cache warmup

```bash
sudo chown -R www-data:www-data /var/www/mediamtx-admin
sudo find /var/www/mediamtx-admin/storage -type d -exec chmod 775 {} \;
sudo find /var/www/mediamtx-admin/bootstrap/cache -type d -exec chmod 775 {} \;

# Keep the JWT private key readable by the web user only
sudo chmod 640 /var/www/mediamtx-admin/storage/app/jwt/private.pem

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:optimize
```

### 11. Apache virtual host

```bash
sudo a2enmod rewrite headers ssl
```

Create `/etc/apache2/sites-available/mediamtx-admin.conf`:

```apache
<VirtualHost *:80>
    ServerName platform.example.com
    DocumentRoot /var/www/mediamtx-admin/public

    <Directory /var/www/mediamtx-admin/public>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/mediamtx-admin-error.log
    CustomLog ${APACHE_LOG_DIR}/mediamtx-admin-access.log combined
</VirtualHost>
```

```bash
sudo a2dissite 000-default.conf
sudo a2ensite mediamtx-admin.conf
sudo systemctl reload apache2
```

### 12. HTTPS (Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d platform.example.com
```

### 13. Queue worker (systemd)

Telegram alerts and notifications are queued, so a worker must run. Create
`/etc/systemd/system/mediamtx-admin-queue.service`:

```ini
[Unit]
Description=MediaMTX Admin queue worker
After=network.target redis-server.service mysql.service

[Service]
User=www-data
Group=www-data
Restart=always
WorkingDirectory=/var/www/mediamtx-admin
ExecStart=/usr/bin/php artisan queue:work --sleep=1 --tries=3 --timeout=60

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now mediamtx-admin-queue
```

### 14. Scheduler (cron)

The scheduler runs the daily prune jobs. Add to `www-data`'s crontab (`sudo crontab -u www-data -e`):

```cron
* * * * * cd /var/www/mediamtx-admin && php artisan schedule:run >> /dev/null 2>&1
```

### 15. MediaMTX

Install MediaMTX (download a release binary or use a container), then configure `mediamtx.yml` to verify JWTs against
this app and to push session alerts back to it:

```yaml
# --- Auth via JWT verified against this app ---
authMethod: jwt
authJWTJWKS: http://127.0.0.1/.well-known/jwks.json   # use 127.0.0.1 on a co‑located VM
authJWTClaimKey: mediamtx_permissions
authJWTInHTTPQuery: true

# --- Telegram alert hooks (under pathDefaults) ---
pathDefaults:
  runOnReady: >
    curl -fsS -m 4 -X POST https://platform.example.com/api/mediamtx/event
    -H "X-Webhook-Secret: <MEDIAMTX_WEBHOOK_SECRET>"
    --data-urlencode "event=ready" --data-urlencode "path=$MTX_PATH"
    --data-urlencode "id=$MTX_SOURCE_ID" --data-urlencode "type=$MTX_SOURCE_TYPE"
  runOnNotReady: >
    curl -fsS -m 4 -X POST https://platform.example.com/api/mediamtx/event
    -H "X-Webhook-Secret: <MEDIAMTX_WEBHOOK_SECRET>"
    --data-urlencode "event=notready" --data-urlencode "path=$MTX_PATH"
    --data-urlencode "id=$MTX_SOURCE_ID" --data-urlencode "type=$MTX_SOURCE_TYPE"
  runOnRead: >
    curl -fsS -m 4 -X POST https://platform.example.com/api/mediamtx/event
    -H "X-Webhook-Secret: <MEDIAMTX_WEBHOOK_SECRET>"
    --data-urlencode "event=read" --data-urlencode "path=$MTX_PATH"
    --data-urlencode "id=$MTX_READER_ID" --data-urlencode "type=$MTX_READER_TYPE"
  runOnUnread: >
    curl -fsS -m 4 -X POST https://platform.example.com/api/mediamtx/event
    -H "X-Webhook-Secret: <MEDIAMTX_WEBHOOK_SECRET>"
    --data-urlencode "event=unread" --data-urlencode "path=$MTX_PATH"
    --data-urlencode "id=$MTX_READER_ID" --data-urlencode "type=$MTX_READER_TYPE"
```

A full reference config is in [`mediamtx.yml.example`](mediamtx.yml.example), and the hook setup is documented in
[`docs/mediamtx-hooks.md`](docs/mediamtx-hooks.md). Restart MediaMTX after editing.

> Set `MEDIAMTX_ALLOWED_IPS` to the IP MediaMTX uses to reach the JWKS endpoint, and put MediaMTX's edge/proxy ranges in
> `MEDIAMTX_TRUSTED_PROXIES` so client IPs resolve correctly.

### 16. Smoke test

```bash
curl -s https://platform.example.com/.well-known/jwks.json   # JWKS (from an allowed IP)
# Log in at https://platform.example.com → change the admin password
# Publish a test stream, open /play, confirm a Telegram alert fires
```

---

## Updating an existing deployment

Run after each `git pull` on the server:

```bash
cd /var/www/mediamtx-admin
php artisan down

git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:optimize

sudo chown -R www-data:www-data storage bootstrap/cache
sudo systemctl restart mediamtx-admin-queue   # reload queued code

php artisan up
```

> Restarting the queue worker after deploy is important — long‑running workers hold the old code in memory until
> restarted.

---

## Maintenance & scheduled tasks

The scheduler (cron, step 14) runs these daily prune jobs to keep high‑traffic tables lean:

```bash
php artisan activity-log:prune --days=30      # trim the activity_log table
php artisan viewer-requests:prune --days=7    # trim the viewer_requests table
```

High‑traffic tables (`viewer_requests`, `activity_log`) carry compound performance indexes added in dedicated
migrations.

---

Built on [Laravel 13](https://laravel.com) and [Filament v5](https://filamentphp.com) (Livewire 4), authorizing
[MediaMTX](https://github.com/bluenviron/mediamtx).
</content>
</invoke>
