# Sessions page: per-publisher Play action (replaces "Open Player" nav item)

## Change

Removed the global **"Open Player"** navigation item (which linked to the public
`/play` player) and replaced it with a per-row **Play** action on the **Live
Sessions** page. The action is shown only for publish sessions.

## Motivation

The sidebar "Open Player" link pointed at `/play` with no stream selected, so an
operator had to know and type a stream path by hand. What operators actually want
is to watch a specific live publisher they already see in the Sessions table.

Keeping the two players separated by purpose:

- **`/play`** — public embeddable / direct-play player. Guarded by
  `player.access` (`PlayerAccessMiddleware`): IP whitelist/blacklist + iframe
  embedding rules. This stays for embeds and public direct playback only.
- **`/monitor/play`** — internal single-stream player. Guarded by `auth` only
  (route `monitor.play`). The new Sessions Play button wires to this route so it
  does **not** touch the public IP/referrer gate.

## Implementation

`app/Providers/Filament/AdminPanelProvider.php`
- Dropped the `NavigationItem::make('Open Player')` entry. `NavigationItem` is
  still imported/used by the Multistream Monitor item.

`app/Filament/Pages/Sessions.php`
- Added a `play` record action:

  ```php
  Action::make('play')
      ->label('Play')
      ->icon('heroicon-o-play')
      ->url(fn (MediamtxSession $record) => route('monitor.play', ['stream' => $record->path]))
      ->openUrlInNewTab()
      ->visible(fn (MediamtxSession $record) => $record->state === 'publish'),
  ```

  `$record->state` is the MediaMTX WebRTC session state; publishers report
  `publish` (readers report `read`), so the button only appears for live
  sources. The player view reads the stream from the `?stream=` query param.

Route `/play` and its middleware are unchanged.

## Verify

1. Log in as super-admin or moderator, open **Live Sessions**.
2. A publishing WebRTC session shows a **Play** action; read-only viewer
   sessions do not.
3. Click **Play** → opens `/monitor/play?stream=<path>` in a new tab and plays
   the live stream.
4. Confirm the sidebar no longer lists "Open Player".

## Deploy

Code-only change. `git push` to the server, then no migration/asset rebuild is
required (Blade/PHP only). Clear caches if config/route caching is enabled:

```bash
php artisan route:clear && php artisan config:clear
```
