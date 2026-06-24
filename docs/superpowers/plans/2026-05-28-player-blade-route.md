# Player Blade Route & IP Access Control — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert `public/player.html` to a Laravel Blade view at `GET /play`, eliminate hardcoded URLs, and add a Filament-managed IP whitelist/blacklist that controls player page access.

**Architecture:** A `PlayerController` serves the Blade view with config-injected URLs. A `PlayerAccessMiddleware` enforces a whitelist or blacklist of IPs stored in `player_ip_rules`, with the mode (whitelist/blacklist) stored in `player_settings`. Both are managed from Filament. A `NavigationItem` in `AdminPanelProvider` links to `/play`.

**Tech Stack:** Laravel 12, PHP 8.2, Filament v3, SQLite (tests), MySQL (production)

---

## File Map

| Action | Path | Responsibility |
|---|---|---|
| Create | `app/Http/Controllers/PlayerController.php` | Serve `player` Blade view with config vars |
| Create | `resources/views/player.blade.php` | WHEP player HTML/JS (Blade version) |
| Create | `app/Models/PlayerIpRule.php` | IP rule model with cache-busting booted hook |
| Create | `app/Models/PlayerSetting.php` | Key-value settings model with static `get()` helper |
| Create | `app/Http/Middleware/PlayerAccessMiddleware.php` | IP whitelist/blacklist enforcement on `/play` |
| Create | `app/Filament/Resources/PlayerIpRuleResource.php` | Filament CRUD for IP rules |
| Create | `app/Filament/Resources/PlayerIpRuleResource/Pages/ListPlayerIpRules.php` | List page with header create action |
| Create | `app/Filament/Pages/PlayerSettings.php` | Filament settings page for mode toggle |
| Create | `resources/views/filament/pages/player-settings.blade.php` | Blade view for settings page |
| Create | `database/migrations/..._create_player_ip_rules_table.php` | player_ip_rules schema |
| Create | `database/migrations/..._create_player_settings_table.php` | player_settings schema + default row |
| Create | `tests/Feature/PlayerControllerTest.php` | Tests for `/play` route |
| Create | `tests/Feature/PlayerAccessMiddlewareTest.php` | Tests for middleware logic |
| Modify | `config/services.php` | Add `mediamtx.stream_base_url` |
| Modify | `.env.example` | Add `MEDIAMTX_STREAM_BASE_URL=` |
| Modify | `bootstrap/app.php` | Register `player.access` middleware alias |
| Modify | `routes/web.php` | Add `GET /play` route |
| Modify | `app/Providers/Filament/AdminPanelProvider.php` | Add `NavigationItem` for Open Player |

---

## Task 1: Config, Blade view, and PlayerController

**Files:**
- Modify: `config/services.php`
- Modify: `.env.example`
- Create: `resources/views/player.blade.php`
- Create: `app/Http/Controllers/PlayerController.php`
- Create: `tests/Feature/PlayerControllerTest.php`

- [ ] **Step 1: Add config key**

In `config/services.php`, add `stream_base_url` to the existing `mediamtx` block (after `allowed_ips`):

```php
'mediamtx' => [
    'api_base_url'    => env('MEDIAMTX_API_BASE_URL', 'http://127.0.0.1:9997'),
    'api_user'        => env('MEDIAMTX_API_USER'),
    'api_pass'        => env('MEDIAMTX_API_PASS'),
    'api_timeout'     => (int) env('MEDIAMTX_API_TIMEOUT', 3),
    'allowed_ips'     => env('MEDIAMTX_ALLOWED_IPS', ''),
    'stale_seconds'   => (int) env('MEDIAMTX_SESSION_STALE_SECONDS', 20),
    'stream_base_url' => env('MEDIAMTX_STREAM_BASE_URL', 'https://stream.jrta.online'),
],
```

In `.env.example`, add after the existing `MEDIAMTX_SESSION_STALE_SECONDS=20` line:

```
MEDIAMTX_STREAM_BASE_URL=
```

- [ ] **Step 2: Create the Blade view**

Create `resources/views/player.blade.php`. This is `public/player.html` verbatim with exactly three substitutions:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="preconnect" href="{{ $whepBaseUrl }}">
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>WebRTC Player</title>
<script defer src="{{ asset('reader.js') }}"></script>
<style>
  html, body {
    margin: 0;
    padding: 0;
    height: 100%;
    width: 100%;
    background: black;
    display: flex;
    justify-content: center;
    align-items: center;
  }
  video {
    width: 100%;
    height: 100%;
    object-fit: contain;
    background: black;
  }
  #loader {
    position: absolute;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    pointer-events: none;
  }
  #spinner.hidden { display: none; }
  #status {
    color: #ccc;
    font-family: sans-serif;
    font-size: 0.9rem;
    pointer-events: none;
    text-align: center;
  }
  #status.error {
    color: #ff6b6b;
  }
  .spinner {
    width: 36px;
    height: 36px;
    border: 3px solid rgba(255,255,255,0.12);
    border-top-color: rgba(255,255,255,0.55);
    border-radius: 50%;
    animation: spin 0.75s linear infinite;
    display: inline-block;
  }
  @keyframes spin { to { transform: rotate(360deg); } }
  #tap-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,0.45);
    cursor: pointer;
  }
  #tap-overlay.hidden { display: none; }
  #tap-overlay svg {
    width: 60px;
    height: 60px;
    fill: rgba(255,255,255,0.85);
  }
  #tap-overlay span {
    color: rgba(255,255,255,0.65);
    font-family: sans-serif;
    font-size: 0.85rem;
    margin-top: 8px;
  }
</style>
</head>
<body>

<video id="myvideo" autoplay playsinline muted disablepictureinpicture></video>
<div id="loader">
  <div id="spinner" class="spinner"></div>
  <div id="status"></div>
</div>
<div id="tap-overlay" class="hidden">
  <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M8 5v14l11-7z"/></svg>
  <span>Tap to play</span>
</div>

<script>
const WHEP_BASE_URL = "{{ $whepBaseUrl }}";

let reader = null;
let token = null;
let retryDelay = 2000;
let refreshTimer = null;
let watchdogTimer = null;
let retryTimer = null;
let starting = false;

const stream = new URLSearchParams(window.location.search).get("stream");

if (!stream) {
  document.body.innerHTML = '<p style="color:#fff;font-family:sans-serif;text-align:center;margin-top:2rem">Missing required <code>?stream=</code> parameter.</p>';
  throw new Error("Missing stream parameter");
}

const whepUrl = `${WHEP_BASE_URL}/${stream}/whep`;
const statusEl = document.getElementById("status");
const videoEl = document.getElementById("myvideo");
const spinnerEl = document.getElementById("spinner");
const tapOverlay = document.getElementById("tap-overlay");

function setStatus(msg, isError = false) {
  statusEl.textContent = msg;
  statusEl.classList.toggle("error", isError);
}
function showLoader(v) { spinnerEl.classList.toggle("hidden", !v); }

function startWatchdog() {
  clearWatchdog();
  watchdogTimer = setTimeout(() => {
    console.warn("WebRTC watchdog: no track received, retrying");
    if (reader) { reader.close(); reader = null; }
    tokenPromise = fetchToken();
    starting = false;
    initPlayer();
  }, 15_000);
}

function clearWatchdog() {
  if (watchdogTimer) { clearTimeout(watchdogTimer); watchdogTimer = null; }
}

videoEl.addEventListener("playing", () => showLoader(false));
videoEl.addEventListener("waiting", () => showLoader(true));

tapOverlay.addEventListener("click", () => {
  videoEl.play()
    .then(() => tapOverlay.classList.add("hidden"))
    .catch(() => {});
});

async function fetchToken() {
  for (let attempt = 0; attempt < 3; attempt++) {
    if (attempt > 0) await new Promise(r => setTimeout(r, 1500 * attempt));
    try {
      const res = await fetch("{{ $tokenEndpoint }}", {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        credentials: "include",
        body: JSON.stringify({ path: stream }),
      });

      // Retry on server errors only
      if (res.status >= 500 && attempt < 2) continue;
      if (!res.ok) throw new Error(`Token request denied (${res.status})`);

      const data = await res.json();
      if (!data.token) throw new Error("JWT not received");

      token = data.token;

      if (refreshTimer) clearTimeout(refreshTimer);
      if (data.expires_at) {
        const msUntilRefresh = data.expires_at * 1000 - Date.now() - 10_000;
        if (msUntilRefresh > 0) {
          refreshTimer = setTimeout(async () => {
            try { tokenPromise = fetchToken(); await tokenPromise; } catch (err) { console.error("Token refresh failed:", err); }
          }, msUntilRefresh);
        }
      }
      return;
    } catch (err) {
      // Don't retry auth denials
      if (err.message.startsWith("Token request denied") || attempt === 2) throw err;
    }
  }
}

// Kick off token fetch immediately — runs in parallel with reader.js codec probing
let tokenPromise = fetchToken();

async function initPlayer() {
  if (starting) return;
  starting = true;

  setStatus("Connecting…");
  showLoader(true);
  startWatchdog();

  try {
    await tokenPromise;
  } catch (err) {
    clearWatchdog();
    setStatus(`Error: ${err.message}`, true);
    showLoader(false);
    starting = false;
    return;
  }

  if (reader) { reader.close(); reader = null; }

  reader = new MediaMTXWebRTCReader({
    url: whepUrl,
    token,
    onError: (err) => {
      console.error("WebRTC error:", err);
      clearWatchdog();
      setStatus("Reconnecting…");
      showLoader(true);
      if (reader) { reader.close(); reader = null; }
      starting = false;
      retryTimer = setTimeout(() => {
        retryTimer = null;
        retryDelay = Math.min(retryDelay * 2, 30_000);
        tokenPromise = fetchToken();
        initPlayer();
      }, retryDelay);
    },
    onTrack: (evt) => {
      clearWatchdog();
      videoEl.srcObject = evt.streams[0];
      videoEl.play().catch((err) => {
        if (err.name === "NotAllowedError") {
          showLoader(false);
          tapOverlay.classList.remove("hidden");
        }
      });
      setStatus("");
      retryDelay = 2000;
    },
  });

  starting = false;
}

// Reconnect immediately when tab becomes visible if stream is not active
document.addEventListener("visibilitychange", () => {
  if (document.visibilityState === "visible" && (!reader || videoEl.paused || videoEl.readyState < 2)) {
    if (retryTimer) { clearTimeout(retryTimer); retryTimer = null; }
    clearWatchdog();
    if (reader) { reader.close(); reader = null; }
    starting = false;
    tokenPromise = fetchToken();
    initPlayer();
  }
});

// defer scripts execute before DOMContentLoaded, so MediaMTXWebRTCReader is ready here
window.addEventListener("DOMContentLoaded", initPlayer);
window.addEventListener("beforeunload", () => {
  clearWatchdog();
  if (retryTimer) clearTimeout(retryTimer);
  if (refreshTimer) clearTimeout(refreshTimer);
  if (reader) reader.close();
});
</script>

</body>
</html>
```

- [ ] **Step 3: Create PlayerController**

Create `app/Http/Controllers/PlayerController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class PlayerController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('player', [
            'whepBaseUrl'   => (string) config('services.mediamtx.stream_base_url', ''),
            'tokenEndpoint' => url('/api/read'),
        ]);
    }
}
```

- [ ] **Step 4: Write the failing test**

Create `tests/Feature/PlayerControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Middleware\PlayerAccessMiddleware;
use Tests\TestCase;

class PlayerControllerTest extends TestCase
{
    public function test_play_route_returns_200(): void
    {
        $response = $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->get('/play');

        $response->assertStatus(200);
    }

    public function test_play_route_injects_whep_base_url(): void
    {
        config(['services.mediamtx.stream_base_url' => 'https://example-stream.test']);

        $response = $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->get('/play');

        $response->assertStatus(200);
        $response->assertSee('https://example-stream.test', escape: false);
    }

    public function test_play_route_injects_token_endpoint(): void
    {
        $response = $this->withoutMiddleware(PlayerAccessMiddleware::class)
            ->get('/play');

        $response->assertStatus(200);
        $response->assertSee('/api/read', escape: false);
    }
}
```

- [ ] **Step 5: Run the test — expect failure (route does not exist yet)**

```bash
php artisan test tests/Feature/PlayerControllerTest.php
```

Expected: FAIL — `404 Not Found` (route not yet registered)

- [ ] **Step 6: Add the route**

In `routes/web.php`, add after the existing JWKS route:

```php
use App\Http\Controllers\PlayerController;

Route::get('/play', PlayerController::class)->middleware('player.access');
```

The `player.access` middleware alias doesn't exist yet — temporarily register a passthrough or add it now. Add the alias to `bootstrap/app.php` `withMiddleware()` block alongside `jwks.ip`:

```php
use App\Http\Middleware\AllowMediamtxOnly;
use App\Http\Middleware\PlayerAccessMiddleware;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'jwks.ip'      => AllowMediamtxOnly::class,
        'player.access' => PlayerAccessMiddleware::class,
    ]);
    $middleware->api(prepend: [
        \Illuminate\Http\Middleware\HandleCors::class,
    ]);
})
```

Create a stub `PlayerAccessMiddleware` that always passes (full implementation in Task 3):

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PlayerAccessMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }
}
```

- [ ] **Step 7: Run the test — expect pass**

```bash
php artisan test tests/Feature/PlayerControllerTest.php
```

Expected: 3 PASS

- [ ] **Step 8: Run Pint**

```bash
./vendor/bin/pint app/Http/Controllers/PlayerController.php app/Http/Middleware/PlayerAccessMiddleware.php routes/web.php bootstrap/app.php
```

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/PlayerController.php \
        app/Http/Middleware/PlayerAccessMiddleware.php \
        resources/views/player.blade.php \
        tests/Feature/PlayerControllerTest.php \
        routes/web.php bootstrap/app.php \
        config/services.php .env.example
git commit -m "feat: add GET /play route with Blade view and PlayerController"
```

---

## Task 2: Migrations and Models

**Files:**
- Create: `database/migrations/YYYY_MM_DD_000001_create_player_ip_rules_table.php`
- Create: `database/migrations/YYYY_MM_DD_000002_create_player_settings_table.php`
- Create: `app/Models/PlayerIpRule.php`
- Create: `app/Models/PlayerSetting.php`

- [ ] **Step 1: Create player_ip_rules migration**

Run:
```bash
php artisan make:migration create_player_ip_rules_table
```

Open the generated file (in `database/migrations/`) and replace its content:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_ip_rules', function (Blueprint $table) {
            $table->id();
            $table->string('ip')->unique();
            $table->string('label')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_ip_rules');
    }
};
```

- [ ] **Step 2: Create player_settings migration**

Run:
```bash
php artisan make:migration create_player_settings_table
```

Open the generated file and replace its content. This migration inserts the default `ip_mode = blacklist` row inline:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            $table->timestamps();
        });

        // Default: blacklist mode (allow all, block listed IPs)
        DB::table('player_settings')->insert([
            'key'        => 'ip_mode',
            'value'      => 'blacklist',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('player_settings');
    }
};
```

- [ ] **Step 3: Run migrations**

```bash
php artisan migrate
```

Expected output: two new tables created.

- [ ] **Step 4: Create PlayerIpRule model**

Create `app/Models/PlayerIpRule.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerIpRule extends Model
{
    protected $fillable = ['ip', 'label'];

    protected static function booted(): void
    {
        $flush = fn () => cache()->forget('player_ip_rules');
        static::created($flush);
        static::updated($flush);
        static::deleted($flush);
    }
}
```

- [ ] **Step 5: Create PlayerSetting model**

Create `app/Models/PlayerSetting.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected static function booted(): void
    {
        $flush = fn () => cache()->forget('player_settings');
        static::created($flush);
        static::updated($flush);
        static::deleted($flush);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::where('key', $key)->value('value') ?? $default;
    }
}
```

- [ ] **Step 6: Run Pint**

```bash
./vendor/bin/pint app/Models/PlayerIpRule.php app/Models/PlayerSetting.php
```

- [ ] **Step 7: Commit**

```bash
git add database/migrations app/Models/PlayerIpRule.php app/Models/PlayerSetting.php
git commit -m "feat: add player_ip_rules and player_settings tables and models"
```

---

## Task 3: PlayerAccessMiddleware (full implementation)

**Files:**
- Modify: `app/Http/Middleware/PlayerAccessMiddleware.php` (replace stub)
- Create: `tests/Feature/PlayerAccessMiddlewareTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/PlayerAccessMiddlewareTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\PlayerIpRule;
use App\Models\PlayerSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerAccessMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure default setting exists (mirrors the data migration)
        PlayerSetting::create(['key' => 'ip_mode', 'value' => 'blacklist']);
        // Clear any cached values from previous tests
        cache()->forget('player_ip_rules');
        cache()->forget('player_settings');
    }

    // ── Blacklist mode ────────────────────────────────────────────────

    public function test_blacklist_mode_allows_unlisted_ip(): void
    {
        $response = $this->get('/play');

        $response->assertStatus(200);
    }

    public function test_blacklist_mode_blocks_listed_ip(): void
    {
        PlayerIpRule::create(['ip' => '1.2.3.4']);

        $response = $this->get('/play', ['REMOTE_ADDR' => '1.2.3.4']);

        $response->assertStatus(403);
    }

    public function test_blacklist_mode_allows_ip_not_in_list(): void
    {
        PlayerIpRule::create(['ip' => '1.2.3.4']);

        $response = $this->get('/play', ['REMOTE_ADDR' => '9.9.9.9']);

        $response->assertStatus(200);
    }

    // ── Whitelist mode ────────────────────────────────────────────────

    public function test_whitelist_mode_blocks_unlisted_ip(): void
    {
        PlayerSetting::where('key', 'ip_mode')->update(['value' => 'whitelist']);

        $response = $this->get('/play', ['REMOTE_ADDR' => '9.9.9.9']);

        $response->assertStatus(403);
    }

    public function test_whitelist_mode_allows_listed_ip(): void
    {
        PlayerSetting::where('key', 'ip_mode')->update(['value' => 'whitelist']);
        PlayerIpRule::create(['ip' => '9.9.9.9']);

        $response = $this->get('/play', ['REMOTE_ADDR' => '9.9.9.9']);

        $response->assertStatus(200);
    }

    public function test_whitelist_mode_with_0000_allows_any_ip(): void
    {
        PlayerSetting::where('key', 'ip_mode')->update(['value' => 'whitelist']);
        PlayerIpRule::create(['ip' => '0.0.0.0']);

        $response = $this->get('/play', ['REMOTE_ADDR' => '9.9.9.9']);

        $response->assertStatus(200);
    }

    public function test_whitelist_mode_blocks_ip_when_only_different_ip_listed(): void
    {
        PlayerSetting::where('key', 'ip_mode')->update(['value' => 'whitelist']);
        PlayerIpRule::create(['ip' => '1.2.3.4']);

        $response = $this->get('/play', ['REMOTE_ADDR' => '9.9.9.9']);

        $response->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run tests — expect failures**

```bash
php artisan test tests/Feature/PlayerAccessMiddlewareTest.php
```

Expected: All tests pass for blacklist (stub always passes), but whitelist tests fail — the stub doesn't enforce anything.

- [ ] **Step 3: Implement the full middleware**

Replace `app/Http/Middleware/PlayerAccessMiddleware.php` with the full implementation:

```php
<?php

namespace App\Http\Middleware;

use App\Models\PlayerIpRule;
use App\Models\PlayerSetting;
use App\Services\ClientIpResolver;
use Closure;
use Illuminate\Http\Request;

class PlayerAccessMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $mode = cache()->remember(
            'player_settings',
            30,
            fn () => PlayerSetting::get('ip_mode', 'blacklist')
        );

        $rules = cache()->remember(
            'player_ip_rules',
            30,
            fn () => PlayerIpRule::pluck('ip')->all()
        );

        $ip = app(ClientIpResolver::class)->resolve($request);

        if ($mode === 'whitelist') {
            // 0.0.0.0 means allow all
            if (in_array('0.0.0.0', $rules, true)) {
                return $next($request);
            }

            if (! in_array($ip, $rules, true)) {
                abort(403);
            }
        } else {
            // blacklist mode: block listed IPs
            if (in_array($ip, $rules, true)) {
                abort(403);
            }
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Run tests — expect all pass**

```bash
php artisan test tests/Feature/PlayerAccessMiddlewareTest.php
```

Expected: 7 PASS

- [ ] **Step 5: Run full test suite**

```bash
php artisan test
```

Expected: all previous tests still pass (pre-existing `ExampleTest` failure for GET / → 302 is acceptable)

- [ ] **Step 6: Run Pint**

```bash
./vendor/bin/pint app/Http/Middleware/PlayerAccessMiddleware.php
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/PlayerAccessMiddleware.php \
        tests/Feature/PlayerAccessMiddlewareTest.php
git commit -m "feat: implement PlayerAccessMiddleware with whitelist/blacklist logic"
```

---

## Task 4: PlayerIpRuleResource (Filament CRUD)

**Files:**
- Create: `app/Filament/Resources/PlayerIpRuleResource.php`
- Create: `app/Filament/Resources/PlayerIpRuleResource/Pages/ListPlayerIpRules.php`

- [ ] **Step 1: Create the Pages directory**

```bash
mkdir -p app/Filament/Resources/PlayerIpRuleResource/Pages
```

- [ ] **Step 2: Create ListPlayerIpRules page**

Create `app/Filament/Resources/PlayerIpRuleResource/Pages/ListPlayerIpRules.php`:

```php
<?php

namespace App\Filament\Resources\PlayerIpRuleResource\Pages;

use App\Filament\Resources\PlayerIpRuleResource;
use App\Models\PlayerIpRule;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPlayerIpRules extends ListRecords
{
    protected static string $resource = PlayerIpRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addRule')
                ->label('Add IP Rule')
                ->icon('heroicon-o-plus')
                ->modalHeading('Add IP Rule')
                ->modalWidth('lg')
                ->form(PlayerIpRuleResource::ipRuleFormSchema())
                ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false)
                ->action(function (array $data): void {
                    PlayerIpRule::create([
                        'ip'    => $data['ip'],
                        'label' => $data['label'] ?? null,
                    ]);
                }),
        ];
    }
}
```

- [ ] **Step 3: Create PlayerIpRuleResource**

Create `app/Filament/Resources/PlayerIpRuleResource.php`:

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlayerIpRuleResource\Pages;
use App\Models\PlayerIpRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PlayerIpRuleResource extends Resource
{
    protected static ?string $model = PlayerIpRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Control';

    protected static ?string $navigationLabel = 'Player IP Rules';

    protected static ?string $modelLabel = 'IP Rule';

    protected static ?string $pluralModelLabel = 'IP Rules';

    protected static ?int $navigationSort = 3;

    public static function ipRuleFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('ip')
                ->label('IP Address')
                ->required()
                ->maxLength(45)
                ->unique(table: PlayerIpRule::class, ignoreRecord: true)
                ->placeholder('192.168.1.1 or 0.0.0.0')
                ->helperText('Use 0.0.0.0 in whitelist mode to allow all IPs.'),

            Forms\Components\TextInput::make('label')
                ->label('Label')
                ->maxLength(255)
                ->placeholder('e.g. Office network'),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema(self::ipRuleFormSchema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ip')
                    ->label('IP Address')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('label')
                    ->label('Label')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime('M d, Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->modalHeading('Edit IP Rule')
                    ->modalWidth('lg')
                    ->form(self::ipRuleFormSchema())
                    ->fillForm(fn (PlayerIpRule $record) => [
                        'ip'    => $record->ip,
                        'label' => $record->label,
                    ])
                    ->action(fn (PlayerIpRule $record, array $data) => $record->update([
                        'ip'    => $data['ip'],
                        'label' => $data['label'] ?? null,
                    ]))
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlayerIpRules::route('/'),
        ];
    }
}
```

- [ ] **Step 4: Run Pint**

```bash
./vendor/bin/pint app/Filament/Resources/PlayerIpRuleResource.php \
                  app/Filament/Resources/PlayerIpRuleResource/Pages/ListPlayerIpRules.php
```

- [ ] **Step 5: Run tests**

```bash
php artisan test
```

Expected: all existing tests still pass.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/PlayerIpRuleResource.php \
        app/Filament/Resources/PlayerIpRuleResource/Pages/
git commit -m "feat: add PlayerIpRuleResource Filament CRUD"
```

---

## Task 5: PlayerSettings Filament Page

**Files:**
- Create: `app/Filament/Pages/PlayerSettings.php`
- Create: `resources/views/filament/pages/player-settings.blade.php`

- [ ] **Step 1: Create the Blade view**

Create `resources/views/filament/pages/player-settings.blade.php`:

```blade
<x-filament-panels::page>
    <x-filament::section>
        <form wire:submit="save">
            {{ $this->form }}

            <div class="mt-6 flex justify-end">
                <x-filament::button type="submit">
                    Save Settings
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
```

- [ ] **Step 2: Create PlayerSettings page**

Create `app/Filament/Pages/PlayerSettings.php`:

```php
<?php

namespace App\Filament\Pages;

use App\Models\PlayerSetting;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class PlayerSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string $view = 'filament.pages.player-settings';

    protected static ?string $navigationLabel = 'Player Access';

    protected static ?string $navigationGroup = 'Control';

    protected static ?int $navigationSort = 4;

    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'ip_mode' => PlayerSetting::get('ip_mode', 'blacklist'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('ip_mode')
                    ->label('Player IP Access Mode')
                    ->options([
                        'blacklist' => 'Blacklist — allow all, block listed IPs',
                        'whitelist' => 'Whitelist — block all, allow listed IPs (use 0.0.0.0 to allow everyone)',
                    ])
                    ->required()
                    ->helperText('Changing mode takes effect within 30 seconds (cache TTL).'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        PlayerSetting::updateOrCreate(
            ['key' => 'ip_mode'],
            ['value' => $data['ip_mode']]
        );

        Notification::make()
            ->title('Player access settings saved')
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }
}
```

- [ ] **Step 3: Run Pint**

```bash
./vendor/bin/pint app/Filament/Pages/PlayerSettings.php
```

- [ ] **Step 4: Run tests**

```bash
php artisan test
```

Expected: all existing tests still pass.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Pages/PlayerSettings.php \
        resources/views/filament/pages/player-settings.blade.php
git commit -m "feat: add PlayerSettings Filament page with whitelist/blacklist mode toggle"
```

---

## Task 6: AdminPanelProvider — Open Player nav link

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`

- [ ] **Step 1: Add NavigationItem**

In `app/Providers/Filament/AdminPanelProvider.php`, add the `navigationItems()` call and import. The full `panel()` method after the change:

```php
<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Widgets\BrowserBreakdownChart;
use App\Filament\Widgets\DeviceBreakdownChart;
use App\Filament\Widgets\OsBreakdownChart;
use App\Filament\Widgets\SessionsOverview;
use App\Filament\Widgets\TopIpsTable;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login(Login::class)
            ->colors([
                'primary' => Color::Red,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                SessionsOverview::class,
                OsBreakdownChart::class,
                BrowserBreakdownChart::class,
                DeviceBreakdownChart::class,
                TopIpsTable::class,
            ])
            ->navigationItems([
                NavigationItem::make('Open Player')
                    ->url('/play', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-play')
                    ->group('Control')
                    ->sort(99),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->renderHook(
                'panels::body.end',
                fn (): string => view('filament.clipboard-listener')->render(),
            );
    }
}
```

- [ ] **Step 2: Run Pint**

```bash
./vendor/bin/pint app/Providers/Filament/AdminPanelProvider.php
```

- [ ] **Step 3: Run tests**

```bash
php artisan test
```

Expected: all existing tests still pass.

- [ ] **Step 4: Commit**

```bash
git add app/Providers/Filament/AdminPanelProvider.php
git commit -m "feat: add Open Player navigation link to Filament admin panel"
```

---

## Done

After all 6 tasks:

- `GET /play` serves the Blade player with config-injected URLs
- `PlayerAccessMiddleware` enforces whitelist/blacklist — manageable from Filament
- `Player IP Rules` resource (Control group) — super-admin + moderator
- `Player Access` settings page (Control group) — super-admin only
- `Open Player` nav link (Control group) — opens `/play` in new tab
- `public/player.html` untouched — backward compatible
