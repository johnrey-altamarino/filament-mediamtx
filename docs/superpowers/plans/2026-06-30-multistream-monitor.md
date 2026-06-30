# Multistream Monitor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a standalone, auth-only, full-screen page that shows a live grid (6 per page, paginated) of the currently-active MediaMTX publish streams, each tile playing the stream with Open/Copy actions.

**Architecture:** A new `MediamtxApi::listPaths()` feeds an `ActivePublishPaths` service that filters to live publishers. A full-page Livewire component (`/monitor`, auth-guarded) renders the grid and polls every 12s. Tiles iframe a new auth-only internal player route (`/monitor/play`) that reuses the existing `player.blade.php` read-only. A new panel nav item opens `/monitor` in a new tab. Nothing in the existing public player path is modified.

**Tech Stack:** Laravel 13, Filament v5, Livewire v3, Tailwind CSS v4, PHPUnit (SQLite in-memory).

## Global Constraints

- PHP 8.2+, Laravel `^13.0`, Filament `^5.0`, Livewire v3 (bundled with Filament).
- **Do not modify** the existing public player: `GET /play` route, `PlayerController`, `PlayerAccessMiddleware`, `resources/views/player.blade.php`, `resources/js/reader.js`. Reuse `player.blade.php` read-only.
- **Do not modify** the existing "Open Player" `NavigationItem` in `AdminPanelProvider`; add a new one alongside it.
- **No changes** to `ReadTokenController` or the read-token / referrer gates — same-origin already passes.
- Roles in this app: `super-admin`, `moderator`, `monitoring`. Every panel user has one of these; the monitor's only guard is being authenticated (`auth` middleware).
- Follow existing service style: `MediamtxApi` methods use `$this->http()->get(...)->throw()->json()`; services use `data_get()` and return safe defaults.
- Run the full suite with `php artisan test`; run a single file with `php artisan test tests/Feature/Foo.php`.
- Code style: run `./vendor/bin/pint` before each commit.

---

### Task 1: `MediamtxApi::listPaths()`

**Files:**
- Modify: `app/Services/MediamtxApi.php` (add method after `listWebRtcSessions()`, ~line 32)
- Test: `tests/Feature/MediamtxApiTest.php` (add one test method)

**Interfaces:**
- Consumes: nothing new.
- Produces: `MediamtxApi::listPaths(): array` — returns the decoded `/v3/paths/list` response (caller reads the `items` key).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/MediamtxApiTest.php`:

```php
    public function test_list_paths_returns_decoded_items(): void
    {
        Http::fake(['*/v3/paths/list' => Http::response(['items' => [
            ['name' => 'live/a', 'ready' => true, 'source' => ['type' => 'webrtcSession']],
        ]], 200)]);

        $res = app(MediamtxApi::class)->listPaths();

        $this->assertSame('live/a', $res['items'][0]['name']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/MediamtxApiTest.php --filter test_list_paths_returns_decoded_items`
Expected: FAIL with "Call to undefined method App\Services\MediamtxApi::listPaths()".

- [ ] **Step 3: Write minimal implementation**

In `app/Services/MediamtxApi.php`, add directly below the `listWebRtcSessions()` method:

```php
    public function listPaths(): array
    {
        return $this->http()->get('/v3/paths/list')->throw()->json();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/MediamtxApiTest.php --filter test_list_paths_returns_decoded_items`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Services/MediamtxApi.php tests/Feature/MediamtxApiTest.php
git add app/Services/MediamtxApi.php tests/Feature/MediamtxApiTest.php
git commit -m "feat: add MediamtxApi::listPaths for the active-paths list"
```

---

### Task 2: `ActivePublishPaths` service

**Files:**
- Create: `app/Services/ActivePublishPaths.php`
- Test: `tests/Feature/ActivePublishPathsTest.php`

**Interfaces:**
- Consumes: `MediamtxApi::listPaths(): array` (Task 1).
- Produces: `ActivePublishPaths::get(): array` — returns a list of
  `['name' => string, 'source_type' => string, 'tracks' => array, 'readers' => int]`,
  filtered to items where `ready === true` and `source !== null`, sorted by `name`. Returns `[]` on API failure.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ActivePublishPathsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\ActivePublishPaths;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ActivePublishPathsTest extends TestCase
{
    public function test_get_returns_only_ready_publishers_sorted_by_name(): void
    {
        Http::fake(['*/v3/paths/list' => Http::response(['items' => [
            ['name' => 'live/b', 'ready' => true, 'source' => ['type' => 'rtmpConn'], 'tracks' => ['H264'], 'readers' => [['type' => 'webrtcSession']]],
            ['name' => 'live/a', 'ready' => true, 'source' => ['type' => 'webrtcSession'], 'tracks' => ['H264', 'MPEG-4 Audio'], 'readers' => []],
            ['name' => 'live/idle', 'ready' => false, 'source' => null, 'tracks' => [], 'readers' => []],
            ['name' => 'live/noSource', 'ready' => true, 'source' => null, 'tracks' => [], 'readers' => []],
        ]], 200)]);

        $result = app(ActivePublishPaths::class)->get();

        $this->assertCount(2, $result);
        $this->assertSame('live/a', $result[0]['name']);
        $this->assertSame('webrtcSession', $result[0]['source_type']);
        $this->assertSame(['H264', 'MPEG-4 Audio'], $result[0]['tracks']);
        $this->assertSame(0, $result[0]['readers']);
        $this->assertSame('live/b', $result[1]['name']);
        $this->assertSame(1, $result[1]['readers']);
    }

    public function test_get_returns_empty_array_on_api_failure(): void
    {
        Http::fake(['*' => Http::response('err', 500)]);

        $this->assertSame([], app(ActivePublishPaths::class)->get());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ActivePublishPathsTest.php`
Expected: FAIL with "Class \"App\Services\ActivePublishPaths\" not found".

- [ ] **Step 3: Write minimal implementation**

Create `app/Services/ActivePublishPaths.php`:

```php
<?php

namespace App\Services;

use Throwable;

class ActivePublishPaths
{
    public function __construct(private readonly MediamtxApi $api) {}

    /**
     * Live publishers only: a path is "active" when MediaMTX reports it ready
     * and it has a source (an actual publisher). Returns a clean, sorted list.
     *
     * @return array<int, array{name: string, source_type: string, tracks: array, readers: int}>
     */
    public function get(): array
    {
        try {
            $items = data_get($this->api->listPaths(), 'items', []);
        } catch (Throwable $e) {
            return [];
        }

        $active = [];
        foreach ($items as $item) {
            if (data_get($item, 'ready') !== true || data_get($item, 'source') === null) {
                continue;
            }

            $active[] = [
                'name' => (string) data_get($item, 'name', ''),
                'source_type' => (string) data_get($item, 'source.type', ''),
                'tracks' => (array) data_get($item, 'tracks', []),
                'readers' => count((array) data_get($item, 'readers', [])),
            ];
        }

        usort($active, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $active;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/ActivePublishPathsTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Services/ActivePublishPaths.php tests/Feature/ActivePublishPathsTest.php
git add app/Services/ActivePublishPaths.php tests/Feature/ActivePublishPathsTest.php
git commit -m "feat: add ActivePublishPaths service filtering live publishers"
```

---

### Task 3: Internal player route + `MonitorPlayerController`

**Files:**
- Create: `app/Http/Controllers/MonitorPlayerController.php`
- Modify: `routes/web.php` (add two `use` imports + the route)
- Test: `tests/Feature/MonitorPlayerControllerTest.php`

**Interfaces:**
- Consumes: existing `player` blade view (read-only), `config('services.mediamtx.stream_base_url')`.
- Produces: route named `monitor.play` (`GET /monitor/play`), auth-guarded, returning the player view with `embedSource = ''` and a `Content-Security-Policy: frame-ancestors 'self'` header.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MonitorPlayerControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorPlayerControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/monitor/play?stream=live/a')->assertRedirect(route('login'));
    }

    public function test_authenticated_user_gets_player_view_with_self_frame_ancestors(): void
    {
        config(['services.mediamtx.stream_base_url' => 'https://stream.test']);

        $response = $this->actingAs(User::factory()->create())
            ->withoutVite()
            ->get('/monitor/play?stream=live/a');

        $response->assertOk();
        $response->assertSee('https://stream.test', escape: false);
        $response->assertSee('/api/read', escape: false);
        $response->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/MonitorPlayerControllerTest.php`
Expected: FAIL — route `/monitor/play` not defined (404 / `route('login')` ok but the authed test 404s).

- [ ] **Step 3: Write minimal implementation**

Create `app/Http/Controllers/MonitorPlayerController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MonitorPlayerController extends Controller
{
    /**
     * Internal single-stream player used by the Multistream Monitor tiles and
     * its "Open" action. Guarded only by auth (route middleware) — it does NOT
     * carry the public player's IP gate, embed/referrer gate, or direct-playback
     * toggle. Reuses the public player view read-only in direct mode: with an
     * empty embed source, the player's same-origin /api/read call passes the
     * referrer gate (referer origin == app origin).
     */
    public function __invoke(Request $request): Response
    {
        $response = response()->view('player', [
            'whepBaseUrl' => (string) config('services.mediamtx.stream_base_url', ''),
            'tokenEndpoint' => url('/api/read'),
            'playbackEnabled' => true,
            'embedSource' => '',
        ]);

        // Only the same-origin monitor page may frame this internal player.
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");

        return $response;
    }
}
```

In `routes/web.php`, add to the `use` block at the top:

```php
use App\Http\Controllers\MonitorPlayerController;
```

And add the route (below the existing `/play` route):

```php
Route::get('/monitor/play', MonitorPlayerController::class)
    ->middleware('auth')
    ->name('monitor.play');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/MonitorPlayerControllerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Http/Controllers/MonitorPlayerController.php routes/web.php tests/Feature/MonitorPlayerControllerTest.php
git add app/Http/Controllers/MonitorPlayerController.php routes/web.php tests/Feature/MonitorPlayerControllerTest.php
git commit -m "feat: add auth-only internal monitor player route"
```

---

### Task 4: `MultistreamMonitor` Livewire page (component, layout, view, route)

**Files:**
- Create: `app/Livewire/MultistreamMonitor.php`
- Create: `resources/views/layouts/monitor.blade.php`
- Create: `resources/views/livewire/multistream-monitor.blade.php`
- Modify: `routes/web.php` (add `use` import + the route)
- Test: `tests/Feature/MultistreamMonitorTest.php`

**Interfaces:**
- Consumes: `ActivePublishPaths::get()` (Task 2); route `monitor.play` (Task 3).
- Produces: route named `monitor` (`GET /monitor`), auth-guarded; full-page Livewire component
  `App\Livewire\MultistreamMonitor` with public `int $page`, public `array $tiles`, and public
  methods `refresh()`, `totalPages(): int`, `currentTiles(): array`, `nextPage()`, `prevPage()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MultistreamMonitorTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\MultistreamMonitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MultistreamMonitorTest extends TestCase
{
    use RefreshDatabase;

    private function fakePaths(int $count): void
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = [
                'name' => "live/p{$i}",
                'ready' => true,
                'source' => ['type' => 'rtmpConn'],
                'tracks' => ['H264'],
                'readers' => [],
            ];
        }

        Http::fake(['*/v3/paths/list' => Http::response(['items' => $items], 200)]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/monitor')->assertRedirect(route('login'));
    }

    public function test_first_page_shows_six_tiles_and_paginates_the_rest(): void
    {
        $this->fakePaths(7);

        Livewire::test(MultistreamMonitor::class)
            ->assertSet('page', 1)
            ->assertSee('live/p1')
            ->assertSee('live/p6')
            ->assertDontSee('live/p7')
            ->call('nextPage')
            ->assertSet('page', 2)
            ->assertSee('live/p7')
            ->assertDontSee('live/p1');
    }

    public function test_empty_state_when_no_active_publishers(): void
    {
        $this->fakePaths(0);

        Livewire::test(MultistreamMonitor::class)
            ->assertSee('No active publishers');
    }

    public function test_page_clamps_down_when_list_shrinks(): void
    {
        $this->fakePaths(7);

        $component = Livewire::test(MultistreamMonitor::class)
            ->call('nextPage')
            ->assertSet('page', 2);

        $this->fakePaths(3); // now only one page worth

        $component->call('refresh')
            ->assertSet('page', 1)
            ->assertSee('live/p1');
    }

    public function test_authenticated_route_renders_grid(): void
    {
        $this->fakePaths(2);

        $this->actingAs(User::factory()->create())
            ->withoutVite()
            ->get('/monitor')
            ->assertOk()
            ->assertSee('live/p1');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/MultistreamMonitorTest.php`
Expected: FAIL — class `App\Livewire\MultistreamMonitor` not found / route `/monitor` undefined.

- [ ] **Step 3: Write the component**

Create `app/Livewire/MultistreamMonitor.php`:

```php
<?php

namespace App\Livewire;

use App\Services\ActivePublishPaths;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.monitor')]
class MultistreamMonitor extends Component
{
    public const PER_PAGE = 6;

    public int $page = 1;

    /** @var array<int, array{name: string, source_type: string, tracks: array, readers: int}> */
    public array $tiles = [];

    public function mount(): void
    {
        $this->refresh();
    }

    /** Reload the active-stream list and keep the page within bounds. */
    public function refresh(): void
    {
        $this->tiles = app(ActivePublishPaths::class)->get();

        $this->page = max(1, min($this->page, $this->totalPages()));
    }

    public function totalPages(): int
    {
        return max(1, (int) ceil(count($this->tiles) / self::PER_PAGE));
    }

    /** @return array<int, array{name: string, source_type: string, tracks: array, readers: int}> */
    public function currentTiles(): array
    {
        return array_slice($this->tiles, ($this->page - 1) * self::PER_PAGE, self::PER_PAGE);
    }

    public function nextPage(): void
    {
        if ($this->page < $this->totalPages()) {
            $this->page++;
        }
    }

    public function prevPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
        }
    }

    public function render()
    {
        return view('livewire.multistream-monitor');
    }
}
```

- [ ] **Step 4: Write the layout**

Create `resources/views/layouts/monitor.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Multistream Monitor</title>
    @vite('resources/css/app.css')
    @livewireStyles
    <style>
        html, body { margin: 0; padding: 0; min-height: 100%; background: #0a0a0a; }
    </style>
</head>
<body>
    {{ $slot }}
    @livewireScripts
</body>
</html>
```

- [ ] **Step 5: Write the component view**

Create `resources/views/livewire/multistream-monitor.blade.php`:

```blade
<div wire:poll.12s="refresh" class="min-h-screen bg-neutral-950 text-neutral-100 p-4">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-lg font-semibold">Multistream Monitor</h1>
        <span class="text-sm text-neutral-400">{{ count($tiles) }} active</span>
    </div>

    @php($visible = $this->currentTiles())

    @if (count($visible) === 0)
        <div class="flex items-center justify-center h-[60vh] text-neutral-500">
            No active publishers.
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($visible as $tile)
                <div wire:key="tile-{{ $tile['name'] }}"
                     class="rounded-lg overflow-hidden bg-black border border-neutral-800">
                    <div class="flex items-center justify-between px-3 py-2 bg-neutral-900">
                        <span class="truncate text-sm font-medium" title="{{ $tile['name'] }}">{{ $tile['name'] }}</span>
                        <span class="text-xs text-neutral-400">{{ $tile['readers'] }} viewers</span>
                    </div>
                    <div class="aspect-video bg-black">
                        <iframe
                            src="{{ route('monitor.play', ['stream' => $tile['name'], 'muted' => 'true', 'controls' => 'false', 'autoplay' => 'true']) }}"
                            class="w-full h-full border-0"
                            allow="autoplay"></iframe>
                    </div>
                    <div class="flex gap-2 px-3 py-2 bg-neutral-900">
                        <a href="{{ route('monitor.play', ['stream' => $tile['name'], 'controls' => 'true']) }}"
                           target="_blank" rel="noopener"
                           class="text-xs px-2 py-1 rounded bg-neutral-800 hover:bg-neutral-700">Open</a>
                        <button type="button"
                            onclick="navigator.clipboard.writeText('{{ route('monitor.play', ['stream' => $tile['name'], 'controls' => 'true']) }}'); this.textContent='Copied'; setTimeout(() => this.textContent='Copy', 1200);"
                            class="text-xs px-2 py-1 rounded bg-neutral-800 hover:bg-neutral-700">Copy</button>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($this->totalPages() > 1)
            <div class="flex items-center justify-center gap-4 mt-6">
                <button wire:click="prevPage" @disabled($page <= 1)
                    class="px-3 py-1 rounded bg-neutral-800 disabled:opacity-40">Prev</button>
                <span class="text-sm text-neutral-400">Page {{ $page }} of {{ $this->totalPages() }}</span>
                <button wire:click="nextPage" @disabled($page >= $this->totalPages())
                    class="px-3 py-1 rounded bg-neutral-800 disabled:opacity-40">Next</button>
            </div>
        @endif
    @endif
</div>
```

- [ ] **Step 6: Register the route**

In `routes/web.php`, add to the `use` block:

```php
use App\Livewire\MultistreamMonitor;
```

And add the route (near the `/monitor/play` route from Task 3):

```php
Route::get('/monitor', MultistreamMonitor::class)
    ->middleware('auth')
    ->name('monitor');
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test tests/Feature/MultistreamMonitorTest.php`
Expected: PASS (5 tests).

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint app/Livewire/MultistreamMonitor.php routes/web.php tests/Feature/MultistreamMonitorTest.php
git add app/Livewire/MultistreamMonitor.php resources/views/layouts/monitor.blade.php resources/views/livewire/multistream-monitor.blade.php routes/web.php tests/Feature/MultistreamMonitorTest.php
git commit -m "feat: add full-screen multistream monitor page"
```

---

### Task 5: Add panel navigation item (open in new tab)

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (extend the existing `->navigationItems([...])` array — do NOT touch the existing "Open Player" item)

**Interfaces:**
- Consumes: route `monitor` (Task 4).
- Produces: a panel nav item "Multistream Monitor" linking to `/monitor` in a new tab, visible to all three roles.

- [ ] **Step 1: Add the nav item**

In `app/Providers/Filament/AdminPanelProvider.php`, inside the existing `->navigationItems([ ... ])` array, add a second item directly after the existing `NavigationItem::make('Open Player')...` item (leave that one unchanged):

```php
                NavigationItem::make('Multistream Monitor')
                    ->url(fn (): string => route('monitor'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-squares-2x2')
                    ->visible(fn (): bool => auth()->user()?->hasAnyRole(['super-admin', 'moderator', 'monitoring']) ?? false)
                    ->sort(98),
```

- [ ] **Step 2: Verify the app boots and the route resolves**

Run: `php artisan route:list --name=monitor`
Expected: lists `GET|HEAD monitor` and `GET|HEAD monitor/play`.

- [ ] **Step 3: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS (including the new Task 1–4 tests).

- [ ] **Step 4: Manual verification (record result)**

1. `composer run dev` (or ensure the app + a MediaMTX with at least one active publisher are running).
2. Log into the panel, confirm a **"Multistream Monitor"** item appears in the sidebar and opens `/monitor` in a **new tab**.
3. Confirm the new tab shows a full-screen grid; an active publish path renders a playing tile with its path name.
4. Confirm **Open** launches the single stream full-screen in a new tab and **Copy** copies the player URL.
5. Confirm that toggling **direct playback off** (PlayerSettings) does **not** break the monitor grid or the Open action.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Providers/Filament/AdminPanelProvider.php
git add app/Providers/Filament/AdminPanelProvider.php
git commit -m "feat: add Multistream Monitor nav item opening in a new tab"
```

---

## Self-Review Notes

- **Spec coverage:** active-paths source (Task 1–2), standalone full-screen auth-only page (Task 4), 6/page pagination + clamp + empty state (Task 4), internal auth-only player bypassing public gates (Task 3), per-tile Open/Copy (Task 4 view), new-tab nav item for all roles (Task 5), existing player/Open-Player untouched (Global Constraints; no edits to those files in any task). All covered.
- **No backend gate changes:** confirmed — `ReadTokenController` and `PlayerAccessMiddleware` are absent from every task's file list.
- **Type consistency:** `listPaths(): array` (Task 1) → `ActivePublishPaths::get()` shape `{name, source_type, tracks, readers}` (Task 2) → consumed unchanged by `currentTiles()` / view (Task 4). `monitor.play` / `monitor` route names consistent across Tasks 3–5.
- **Vite in tests:** feature tests that render Blade with `@vite` call `->withoutVite()` to avoid a build-manifest dependency.
