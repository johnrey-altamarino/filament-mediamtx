# Player Blade Route & IP Access Control — Design Spec
**Date:** 2026-05-28
**Branch:** developv2

---

## Overview

Convert `public/player.html` into a Laravel Blade view served at `GET /play`, with config-driven URLs replacing hardcoded production values. Add a Filament-managed IP whitelist/blacklist that controls who can load the player page. Add a Filament navigation link that opens the player in a new tab.

---

## Section 1 — Route & Blade Template

### Route

Add to `routes/web.php`:

```php
Route::get('/play', PlayerController::class)->middleware('player.access');
```

### Controller

`app/Http/Controllers/PlayerController.php` — invokable, no auth:

```php
return view('player', [
    'whepBaseUrl'   => config('services.mediamtx.stream_base_url'),
    'tokenEndpoint' => url('/api/read'),
]);
```

### Blade Template

`resources/views/player.blade.php` — identical to `public/player.html` with three substitutions:

| Old (hardcoded) | New (Blade) |
|---|---|
| `./reader.js` | `{{ asset('reader.js') }}` |
| `"https://stream.jrta.online"` | `"{{ $whepBaseUrl }}"` |
| `"https://platform.jrta.online/api/read"` | `"{{ $tokenEndpoint }}"` |

All existing JS logic (token retry, watchdog, visibility reconnect, tap overlay) is preserved unchanged.

### Config

Add to `config/services.php` under the `mediamtx` block:

```php
'stream_base_url' => env('MEDIAMTX_STREAM_BASE_URL', 'https://stream.jrta.online'),
```

Add to `.env.example`:

```
MEDIAMTX_STREAM_BASE_URL=
```

### Backward compatibility

`public/player.html` is **kept** (not deleted). Existing embeds pointing to `/player.html` continue to work. `/play` is the new canonical URL.

---

## Section 2 — IP Access Control

### Database

**Migration 1:** `create_player_ip_rules_table`

| Column | Type | Constraints |
|---|---|---|
| `id` | bigint | PK |
| `ip` | string | unique, not null |
| `label` | string | nullable |
| `created_at` / `updated_at` | timestamps | |

**Migration 2:** `create_player_settings_table`

| Column | Type | Constraints |
|---|---|---|
| `id` | bigint | PK |
| `key` | string | unique, not null |
| `value` | text | not null |
| `created_at` / `updated_at` | timestamps | |

**Default data:** The `create_player_settings_table` migration inserts the default row `{ key: 'ip_mode', value: 'blacklist' }` inline (data migration). No seeder file needed. No default IP rules inserted — blacklist mode with no entries means all traffic is allowed.

### Models

- `App\Models\PlayerIpRule` — fillable: `ip`, `label`
- `App\Models\PlayerSetting` — fillable: `key`, `value`. Static helper:
  ```php
  public static function get(string $key, mixed $default = null): mixed
  ```

### Middleware

`app/Http/Middleware/PlayerAccessMiddleware.php` — registered as alias `player.access`.

**Logic:**

```
mode  = PlayerSetting::get('ip_mode', 'blacklist')   [cached 30s, key: 'player_settings']
rules = PlayerIpRule::pluck('ip')->all()              [cached 30s, key: 'player_ip_rules']
ip    = app(ClientIpResolver::class)->resolve($request)

if mode == 'whitelist':
    if '0.0.0.0' in rules → pass
    if ip in rules         → pass
    else                   → abort(403)

if mode == 'blacklist':
    if ip in rules         → abort(403)
    else                   → pass
```

Both cache keys are flushed when rules or settings are saved from Filament.

### Middleware registration

Register alias `player.access` → `PlayerAccessMiddleware` in `bootstrap/app.php` `withMiddleware()` block.

---

## Section 3 — Filament Integration

### PlayerIpRuleResource

`app/Filament/Resources/PlayerIpRuleResource.php`

- **Navigation:** group `Control`, icon `heroicon-o-shield-check`, label `Player IP Rules`
- **Table columns:** `ip`, `label`, `created_at` (sortable, toggleable)
- **Form fields:** `ip` (required, unique validation, placeholder `192.168.1.1 or 0.0.0.0`), `label` (optional text)
- **On create/edit/delete:** flush `player_ip_rules` cache key
- **Access:** `super-admin` + `moderator` (`canViewAny`, `canCreate`, `canEdit`, `canDelete`)
- `0.0.0.0` in whitelist mode means "allow all" — documented in the form field helper text

### PlayerSettings Filament Page

`app/Filament/Pages/PlayerSettings.php`

- **URL:** `/player-settings`
- **Navigation:** group `Control`, icon `heroicon-o-adjustments-horizontal`, label `Player Access`
- **UI:** Single `Select` with options `Blacklist` / `Whitelist` — reads current value from `player_settings`, saves on submit
- **On save:** flush `player_settings` cache key
- **Access:** `super-admin` only (`canAccess()`)

### Navigation link to `/play`

Registered in `app/Providers/Filament/AdminPanelProvider.php` as a custom `NavigationItem`:

```php
NavigationItem::make('Open Player')
    ->url('/play', shouldOpenInNewTab: true)
    ->icon('heroicon-o-play')
    ->group('Control')
    ->sort(99)
```

Opens `/play` in a new tab. Admin appends `?stream=<path>` manually to the URL.

### Access matrix

| Feature | super-admin | moderator | monitoring |
|---|---|---|---|
| Manage IP rules (CRUD) | ✅ | ✅ | ❌ |
| Change mode (whitelist/blacklist) | ✅ | ❌ | ❌ |
| Open Player nav link | ✅ | ✅ | ✅ |

---

## Files Created / Modified

| Action | Path |
|---|---|
| Create | `app/Http/Controllers/PlayerController.php` |
| Create | `app/Http/Middleware/PlayerAccessMiddleware.php` |
| Create | `app/Models/PlayerIpRule.php` |
| Create | `app/Models/PlayerSetting.php` |
| Create | `app/Filament/Resources/PlayerIpRuleResource.php` |
| Create | `app/Filament/Pages/PlayerSettings.php` |
| Create | `resources/views/player.blade.php` |
| Create | `database/migrations/xxxx_create_player_ip_rules_table.php` |
| Create | `database/migrations/xxxx_create_player_settings_table.php` |
| Modify | `routes/web.php` |
| Modify | `config/services.php` |
| Modify | `.env.example` |
| Modify | `bootstrap/app.php` |
| Modify | `app/Providers/Filament/AdminPanelProvider.php` |
| Keep | `public/player.html` (backward compat) |
