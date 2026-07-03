# Confine `monitoring` Role to `/monitor`

**Date:** 2026-07-03
**Status:** Approved

## Goal

A user whose only role is `monitoring` should be able to reach the Multistream
Monitor (`/monitor` and its player `/monitor/play`) and nothing else. Every
admin-panel URL — the dashboard, any resource, deep links — and the post-login
landing page must redirect such a user to `/monitor`.

## Background

- `/monitor` (`MultistreamMonitor` Livewire component) and `/monitor/play`
  (`MonitorPlayerController`) are standalone routes in `routes/web.php` guarded
  by plain `auth` middleware. They are **not** part of the Filament panel.
- The Filament admin panel is served at root path `''`
  (`AdminPanelProvider`).
- Today the `monitoring` role:
  - passes `User::canAccessPanel()` (`app/Models/User.php`),
  - has `canViewAny() === true` on `PublishTokenResource`,
    `AllowedReferrerResource`, and `BlockedIpResource`,
  - sees the default Filament `Dashboard`,
  - is redirected to the dashboard after login (Filament default).

## Definition: "monitoring-only"

A user is **monitoring-only** when they hold the `monitoring` role **and** do
**not** hold `super-admin` or `moderator`. A user with an elevated role plus
`monitoring` keeps full panel access.

This rule lives in one place:

```php
// app/Models/User.php
public function isMonitoringOnly(): bool
{
    return $this->hasRole('monitoring')
        && ! $this->hasAnyRole(['super-admin', 'moderator']);
}
```

## Design

Three coordinated pieces enforce the hard lock.

### 1. Panel middleware — the hard lock

New middleware `App\Http\Middleware\EnsureMonitoringUsersUseMonitor`, added to
the Filament panel's `->middleware([...])` stack in `AdminPanelProvider`.

Behaviour:

- If there is an authenticated user **and** `$user->isMonitoringOnly()`, return
  `redirect()->route('monitor')`.
- Otherwise pass through (`$next($request)`).

Because it checks for an authenticated monitoring-only user, it is a no-op on the
login route and for unauthenticated requests, so the login flow is unaffected.
This middleware is what actually confines the user: it catches direct URL typing,
deep links, and bookmarked panel pages.

Guard against a redirect loop: `/monitor` is outside the panel, so the panel
middleware never runs for it — no loop is possible. (The middleware also only
triggers a redirect for panel routes.)

### 2. Custom `LoginResponse` — clean post-login redirect

Bind `Filament\Http\Responses\Auth\Contracts\LoginResponse` to a custom
implementation in `AppServiceProvider::register()`:

- If the freshly authenticated user `isMonitoringOnly()`, redirect to
  `route('monitor')`.
- Otherwise defer to Filament's default login redirect behaviour.

This avoids bouncing a monitoring user through the dashboard (where the
middleware would redirect them anyway) — they go straight to `/monitor`.

### 3. Strip resource access — defense in depth

Remove `'monitoring'` from the `canViewAny()` role lists on:

- `PublishTokenResource`
- `AllowedReferrerResource`
- `BlockedIpResource`

The middleware already prevents monitoring users from reaching these, but
removing the grant keeps authorization honest and the sidebar clean if the
middleware is ever changed.

### Unchanged

- `User::canAccessPanel()` still returns `true` for `monitoring`. Filament
  requires panel access for the login flow to complete; denying it would produce
  a 403 after login instead of our redirect. Confinement is enforced by the
  middleware, not by revoking panel access.
- The "Multistream Monitor" navigation item and `/monitor` routes are unchanged.

## Testing

Feature tests (SQLite in-memory, seed the three roles):

1. A monitoring-only user requesting `/` (dashboard) is redirected to
   `/monitor`.
2. A monitoring-only user requesting a resource index (e.g. the
   `PublishTokenResource` list page) is redirected to `/monitor`.
3. A monitoring-only user is redirected to `/monitor` after logging in.
4. A monitoring-only user can successfully load `/monitor` (200, not a
   redirect).
5. A `moderator` and a `super-admin` can still load the dashboard (no redirect).

## Out of Scope

- Changing what the `/monitor` page itself shows.
- Altering `moderator` / `super-admin` permissions.
- Any change to the read-token / JWT flow.
