# Monitoring role confinement

Users whose only role is `monitoring` are locked to the Multistream Monitor
(`/monitor`) and cannot reach the admin panel.

## Behaviour

- After login, a monitoring-only user is redirected to `/monitor`.
- Any admin-panel URL (dashboard, resources, deep links) redirects a
  monitoring-only user to `/monitor`.
- The logout route is whitelisted by the confinement middleware, and `/monitor`
  now has a **Logout** button, so monitoring users can still sign out.
- Users who also hold `super-admin` or `moderator` are unaffected and keep full
  panel access (they can view `/monitor` too).

## "Monitoring-only" definition

A user is monitoring-only when they hold the `monitoring` role **and not**
`super-admin` or `moderator`. Encapsulated in `User::isMonitoringOnly()`.

## Implementation

- `User::isMonitoringOnly()` — `hasRole('monitoring') && ! hasAnyRole(['super-admin', 'moderator'])`.
- `App\Http\Middleware\EnsureMonitoringUsersUseMonitor` — registered on the
  admin panel's middleware stack; redirects monitoring-only users to `/monitor`
  and whitelists `filament.admin.auth.logout` so they can sign out. It is a
  no-op for unauthenticated requests, so the login page still works. `/monitor`
  is a plain web route (not part of the panel), so the middleware never runs for
  it — no redirect loop.
- `App\Http\Responses\LoginResponse` — bound to Filament's `LoginResponse`
  contract in `AppServiceProvider::boot()`; redirects monitoring-only users to
  `/monitor` after login, defers to Filament's default for everyone else.
- `canViewAny()` on `PublishTokenResource`, `AllowedReferrerResource`, and
  `BlockedIpResource` no longer grants `monitoring` (defense in depth).
- Logout button added to the header of
  `resources/views/livewire/multistream-monitor.blade.php`.

## Notes

- `User::canAccessPanel()` still returns `true` for `monitoring`. Filament
  requires panel access for the login flow to complete; confinement is enforced
  by the middleware, not by revoking panel access (which would 403 after login).
- The Filament version in this project is **v5**. The `LoginResponse` contract
  lives at `Filament\Auth\Http\Responses\Contracts\LoginResponse`, and the
  binding must be registered in `boot()` (not `register()`) so it wins over
  Filament's own binding.

## Tests

- `tests/Feature/UserRolesTest.php` — `isMonitoringOnly()` truth table.
- `tests/Feature/MonitoringConfinementTest.php` — redirects from dashboard /
  resource, monitor loads, logout reachable, elevated roles unaffected, gated
  resources hidden.
- `tests/Feature/MonitoringLoginRedirectTest.php` — post-login redirect.
- `tests/Feature/MultistreamMonitorTest.php` — logout control present on
  `/monitor`.
