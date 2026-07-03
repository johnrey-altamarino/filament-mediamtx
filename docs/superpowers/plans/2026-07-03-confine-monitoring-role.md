# Confine `monitoring` Role to `/monitor` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lock users whose only role is `monitoring` to the `/monitor` page — redirect them there after login and from any admin-panel URL — while leaving `super-admin`/`moderator` unaffected.

**Architecture:** A `User::isMonitoringOnly()` helper defines the rule in one place. A Filament panel middleware redirects monitoring-only users from any panel route to `/monitor` (whitelisting logout). A custom `LoginResponse` sends them straight to `/monitor` after login. The three resources they used to see drop the `monitoring` grant, and `/monitor` gains a logout button.

**Tech Stack:** Laravel 12, PHP 8.2, Filament v3, Spatie Permissions, PHPUnit (SQLite in-memory).

## Global Constraints

- PHP 8.2+, Laravel 12, Filament v3.
- Tests run on SQLite in-memory (`phpunit.xml` already sets this).
- Roles come from Spatie Permissions; seed with `RolesSeeder` (`super-admin`, `moderator`, `monitoring`) in tests before assigning.
- Access control in this codebase uses inline `hasRole` / `hasAnyRole` checks, not policy classes. Follow that pattern.
- Run `./vendor/bin/pint` on touched files before committing.
- Route names: dashboard `filament.admin.pages.dashboard` (`/`), monitor `monitor` (`/monitor`), publish tokens index `filament.admin.resources.publish-tokens.index` (`/publish-tokens`), logout `filament.admin.auth.logout` (`POST /logout`), login page `/login`.

---

### Task 1: `User::isMonitoringOnly()` helper

**Files:**
- Modify: `app/Models/User.php` (add method near `isSuperAdmin()`, ~line 66-69)
- Test: `tests/Feature/UserRolesTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: `User::isMonitoringOnly(): bool` — true when the user has role `monitoring` and does NOT have `super-admin` or `moderator`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/UserRolesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    public function test_monitoring_only_user_is_monitoring_only(): void
    {
        $user = User::factory()->create();
        $user->assignRole('monitoring');

        $this->assertTrue($user->isMonitoringOnly());
    }

    public function test_user_with_elevated_role_is_not_monitoring_only(): void
    {
        $user = User::factory()->create();
        $user->assignRole(['monitoring', 'moderator']);

        $this->assertFalse($user->isMonitoringOnly());
    }

    public function test_user_without_monitoring_is_not_monitoring_only(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $this->assertFalse($user->isMonitoringOnly());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter UserRolesTest`
Expected: FAIL — `Call to undefined method App\Models\User::isMonitoringOnly()`.

- [ ] **Step 3: Add the method**

In `app/Models/User.php`, add after the `isSuperAdmin()` method (before the closing brace of the class):

```php
    public function isMonitoringOnly(): bool
    {
        return $this->hasRole('monitoring')
            && ! $this->hasAnyRole(['super-admin', 'moderator']);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter UserRolesTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Models/User.php tests/Feature/UserRolesTest.php
git add app/Models/User.php tests/Feature/UserRolesTest.php
git commit -m "feat: add User::isMonitoringOnly helper"
```

---

### Task 2: `EnsureMonitoringUsersUseMonitor` middleware

**Files:**
- Create: `app/Http/Middleware/EnsureMonitoringUsersUseMonitor.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (append to the `->middleware([...])` array, ~line 63-73)
- Test: `tests/Feature/MonitoringConfinementTest.php` (create)

**Interfaces:**
- Consumes: `User::isMonitoringOnly()` (Task 1).
- Produces: middleware class `App\Http\Middleware\EnsureMonitoringUsersUseMonitor` registered on the admin panel; monitoring-only users get `302` to `route('monitor')` on any panel route except logout.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MonitoringConfinementTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringConfinementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        // Rendering /monitor calls the MediaMTX paths API; stub it so the
        // "can load monitor" test never touches the network.
        Http::fake(['*/v3/paths/list' => Http::response(['items' => []], 200)]);
    }

    private function monitoringUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('monitoring');

        return $user;
    }

    public function test_monitoring_user_is_redirected_from_dashboard(): void
    {
        $this->actingAs($this->monitoringUser())
            ->get('/')
            ->assertRedirect(route('monitor'));
    }

    public function test_monitoring_user_is_redirected_from_a_resource(): void
    {
        $this->actingAs($this->monitoringUser())
            ->get('/publish-tokens')
            ->assertRedirect(route('monitor'));
    }

    public function test_monitoring_user_can_load_monitor(): void
    {
        $this->actingAs($this->monitoringUser())
            ->withoutVite()
            ->get('/monitor')
            ->assertOk();
    }

    public function test_moderator_can_load_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('moderator');

        $this->actingAs($user)
            ->withoutVite()
            ->get('/')
            ->assertOk();
    }

    public function test_super_admin_can_load_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $this->actingAs($user)
            ->withoutVite()
            ->get('/')
            ->assertOk();
    }

    public function test_monitoring_user_can_reach_logout(): void
    {
        $this->actingAs($this->monitoringUser())
            ->post('/logout')
            ->assertRedirect('/login');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter MonitoringConfinementTest`
Expected: FAIL — `test_monitoring_user_is_redirected_from_dashboard` and `test_monitoring_user_is_redirected_from_a_resource` fail (they currently get `200`/`403`, not a redirect to `/monitor`).

- [ ] **Step 3: Create the middleware**

Create `app/Http/Middleware/EnsureMonitoringUsersUseMonitor.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMonitoringUsersUseMonitor
{
    /**
     * Redirect monitoring-only users away from the admin panel to /monitor.
     *
     * Logout is whitelisted so those users can still sign out.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('filament.admin.auth.logout')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user !== null && $user->isMonitoringOnly()) {
            return redirect()->route('monitor');
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the middleware on the panel**

In `app/Providers/Filament/AdminPanelProvider.php`, add the import near the other `use` statements:

```php
use App\Http\Middleware\EnsureMonitoringUsersUseMonitor;
```

Then append it to the end of the `->middleware([...])` array (after `DispatchServingFilamentEvent::class,`):

```php
                DispatchServingFilamentEvent::class,
                EnsureMonitoringUsersUseMonitor::class,
            ])
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter MonitoringConfinementTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint app/Http/Middleware/EnsureMonitoringUsersUseMonitor.php app/Providers/Filament/AdminPanelProvider.php tests/Feature/MonitoringConfinementTest.php
git add app/Http/Middleware/EnsureMonitoringUsersUseMonitor.php app/Providers/Filament/AdminPanelProvider.php tests/Feature/MonitoringConfinementTest.php
git commit -m "feat: confine monitoring-only users to /monitor via panel middleware"
```

---

### Task 3: Custom `LoginResponse` redirect

**Files:**
- Create: `app/Http/Responses/LoginResponse.php`
- Modify: `app/Providers/AppServiceProvider.php` (`register()` method)
- Test: `tests/Feature/MonitoringLoginRedirectTest.php` (create)

**Interfaces:**
- Consumes: `User::isMonitoringOnly()` (Task 1).
- Produces: binding of `Filament\Http\Responses\Auth\Contracts\LoginResponse` to `App\Http\Responses\LoginResponse`, which redirects monitoring-only users to `route('monitor')` after login and defers to Filament's default for everyone else.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MonitoringLoginRedirectTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringLoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    public function test_login_response_redirects_monitoring_user_to_monitor(): void
    {
        $user = User::factory()->create();
        $user->assignRole('monitoring');

        $response = $this->actingAs($user)
            ->app->make(LoginResponse::class)
            ->toResponse(request());

        $this->assertEquals(route('monitor'), $response->getTargetUrl());
    }

    public function test_login_response_does_not_redirect_admin_to_monitor(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $response = $this->actingAs($user)
            ->app->make(LoginResponse::class)
            ->toResponse(request());

        $this->assertNotEquals(route('monitor'), $response->getTargetUrl());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter MonitoringLoginRedirectTest`
Expected: FAIL — `test_login_response_redirects_monitoring_user_to_monitor` fails because the default `LoginResponse` targets the dashboard, not `/monitor`.

- [ ] **Step 3: Create the custom LoginResponse**

Create `app/Http/Responses/LoginResponse.php`:

```php
<?php

namespace App\Http\Responses;

use Filament\Http\Responses\Auth\LoginResponse as BaseLoginResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LoginResponse extends BaseLoginResponse
{
    public function toResponse($request): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null && $user->isMonitoringOnly()) {
            return redirect()->route('monitor');
        }

        return parent::toResponse($request);
    }
}
```

- [ ] **Step 4: Bind it in AppServiceProvider**

In `app/Providers/AppServiceProvider.php`, add the imports at the top:

```php
use App\Http\Responses\LoginResponse;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
```

Inside the `register()` method body, add:

```php
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter MonitoringLoginRedirectTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint app/Http/Responses/LoginResponse.php app/Providers/AppServiceProvider.php tests/Feature/MonitoringLoginRedirectTest.php
git add app/Http/Responses/LoginResponse.php app/Providers/AppServiceProvider.php tests/Feature/MonitoringLoginRedirectTest.php
git commit -m "feat: redirect monitoring-only users to /monitor after login"
```

---

### Task 4: Remove `monitoring` grant from the three resources

**Files:**
- Modify: `app/Filament/Resources/PublishTokenResource.php:212`
- Modify: `app/Filament/Resources/AllowedReferrerResource.php:143`
- Modify: `app/Filament/Resources/BlockedIpResource.php:153`
- Test: append to `tests/Feature/MonitoringConfinementTest.php` (Task 2)

**Interfaces:**
- Consumes: nothing new.
- Produces: `canViewAny()` on all three resources returns `false` for a monitoring-only user.

- [ ] **Step 1: Write the failing test**

Append these methods to `tests/Feature/MonitoringConfinementTest.php` (inside the class, before the closing brace):

```php
    public function test_monitoring_user_cannot_view_gated_resources(): void
    {
        $this->actingAs($this->monitoringUser());

        $this->assertFalse(\App\Filament\Resources\PublishTokenResource::canViewAny());
        $this->assertFalse(\App\Filament\Resources\AllowedReferrerResource::canViewAny());
        $this->assertFalse(\App\Filament\Resources\BlockedIpResource::canViewAny());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter test_monitoring_user_cannot_view_gated_resources`
Expected: FAIL — `canViewAny()` still returns `true` for monitoring (each resource lists `'monitoring'`).

- [ ] **Step 3: Update the three `canViewAny()` methods**

In `app/Filament/Resources/PublishTokenResource.php` (line 212), change:

```php
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator', 'monitoring']) ?? false;
```
to:
```php
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false;
```

In `app/Filament/Resources/AllowedReferrerResource.php` (line 143), change:

```php
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator', 'monitoring']) ?? false;
```
to:
```php
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false;
```

In `app/Filament/Resources/BlockedIpResource.php` (line 153), change:

```php
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator', 'monitoring']) ?? false;
```
to:
```php
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false;
```

Note: also check `PublishTokenResource.php:83` — a `visible()` callback on a copy action lists `'monitoring'`. Leave it unchanged; monitoring users never reach that page now, and the design does not call for touching action-level gates.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter MonitoringConfinementTest`
Expected: PASS (7 tests including the new one).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Filament/Resources/PublishTokenResource.php app/Filament/Resources/AllowedReferrerResource.php app/Filament/Resources/BlockedIpResource.php tests/Feature/MonitoringConfinementTest.php
git add app/Filament/Resources/PublishTokenResource.php app/Filament/Resources/AllowedReferrerResource.php app/Filament/Resources/BlockedIpResource.php tests/Feature/MonitoringConfinementTest.php
git commit -m "chore: drop monitoring role from gated resource canViewAny"
```

---

### Task 5: Logout control on `/monitor`

**Files:**
- Modify: `resources/views/livewire/multistream-monitor.blade.php:2-5` (header row)
- Test: append to `tests/Feature/MultistreamMonitorTest.php`

**Interfaces:**
- Consumes: `route('filament.admin.auth.logout')`.
- Produces: a visible Logout `POST` form in the `/monitor` header.

- [ ] **Step 1: Write the failing test**

First, open `tests/Feature/MultistreamMonitorTest.php` to match its existing setup (it renders the Livewire component or GETs `/monitor`). Append a test that asserts the logout action is present. Add this method inside the class:

```php
    public function test_monitor_page_has_logout_control(): void
    {
        $this->fakePaths(0);

        $response = $this->actingAs(User::factory()->create())
            ->withoutVite()
            ->get('/monitor');

        $response->assertOk();
        $response->assertSee(route('filament.admin.auth.logout'), escape: false);
    }
```

`User`, `Http`, and the `fakePaths()` helper already exist in that file — reuse
them. `fakePaths(0)` stubs the MediaMTX paths API with an empty list so the GET
never touches the network.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter test_monitor_page_has_logout_control`
Expected: FAIL — the logout URL does not appear on the page.

- [ ] **Step 3: Add the logout form to the header**

In `resources/views/livewire/multistream-monitor.blade.php`, replace the header block (lines 2-5):

```blade
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-lg font-semibold">Multistream Monitor</h1>
        <span class="text-sm text-neutral-400">{{ count($tiles) }} active</span>
    </div>
```

with:

```blade
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-lg font-semibold">Multistream Monitor</h1>
        <div class="flex items-center gap-3">
            <span class="text-sm text-neutral-400">{{ count($tiles) }} active</span>
            <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
                @csrf
                <button type="submit"
                    class="text-xs px-2 py-1 rounded bg-neutral-800 hover:bg-neutral-700 text-neutral-200">
                    Logout
                </button>
            </form>
        </div>
    </div>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter MultistreamMonitorTest`
Expected: PASS (existing tests plus the new one).

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/multistream-monitor.blade.php tests/Feature/MultistreamMonitorTest.php
git commit -m "feat: add logout control to multistream monitor"
```

---

### Task 6: Full suite + docs

**Files:**
- Create: `docs/monitoring-role-confinement.md`

- [ ] **Step 1: Run the new/changed tests per-file**

Run each new/changed test file in isolation (the full suite has 8 pre-existing
failures from `RefreshDatabase` state bleed in `ExampleTest`,
`PlayerAccessMiddlewareTest`, `PlayerControllerTest` — unrelated to this work,
they pass in isolation):

```bash
php artisan test tests/Feature/UserRolesTest.php \
  tests/Feature/MonitoringConfinementTest.php \
  tests/Feature/MonitoringLoginRedirectTest.php \
  tests/Feature/MultistreamMonitorTest.php
```

Expected: PASS — all tests in these four files green.

- [ ] **Step 2: Write the change doc**

Per the project convention (document every update), create `docs/monitoring-role-confinement.md`:

```markdown
# Monitoring role confinement

Users whose only role is `monitoring` are locked to the Multistream Monitor
(`/monitor`) and cannot reach the admin panel.

## Behaviour

- After login, a monitoring-only user is redirected to `/monitor`.
- Any admin-panel URL (dashboard, resources, deep links) redirects a
  monitoring-only user to `/monitor`.
- The logout route is whitelisted, and `/monitor` now has a Logout button, so
  monitoring users can still sign out.
- Users who also hold `super-admin` or `moderator` are unaffected and keep full
  panel access (they can view `/monitor` too).

## Implementation

- `User::isMonitoringOnly()` — has `monitoring`, lacks `super-admin`/`moderator`.
- `App\Http\Middleware\EnsureMonitoringUsersUseMonitor` — registered on the
  admin panel; redirects monitoring-only users to `/monitor` (whitelists
  `filament.admin.auth.logout`).
- `App\Http\Responses\LoginResponse` — bound in `AppServiceProvider`; redirects
  monitoring-only users to `/monitor` after login.
- `canViewAny()` on `PublishTokenResource`, `AllowedReferrerResource`, and
  `BlockedIpResource` no longer grants `monitoring`.
- Logout button added to `resources/views/livewire/multistream-monitor.blade.php`.
```

- [ ] **Step 3: Commit**

```bash
git add docs/monitoring-role-confinement.md
git commit -m "docs: document monitoring role confinement"
```

---

## Self-Review Notes

- **Spec coverage:** middleware (§1) → Task 2; LoginResponse (§2) → Task 3; resource stripping (§3) → Task 4; logout whitelist + button (§ logout additions) → Tasks 2 & 5; `isMonitoringOnly` definition → Task 1; testing §1-6 → Tasks 1-5. All covered.
- **Placeholders:** none — every code and command step is concrete.
- **Type consistency:** `isMonitoringOnly(): bool` defined in Task 1 is used identically in Tasks 2 and 3. Route names match `route:list` output.
- **Unverified detail to confirm during execution:** Task 5 assumes `tests/Feature/MultistreamMonitorTest.php` authenticates and GETs `/monitor`; open it first and adapt the new test to the file's existing style if it renders the component via Livewire instead.
