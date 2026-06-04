# MediaMTX → Telegram alert hooks

These hooks make MediaMTX notify the Laravel app the instant a stream or viewer
starts/stops. No background polling is involved.

## 1. Laravel `.env`

```
TELEGRAM_ALERTS_ENABLED=true
TELEGRAM_BOT_TOKEN=<token from @BotFather>
TELEGRAM_CHAT_ID=<your chat or channel id>
MEDIAMTX_WEBHOOK_SECRET=<a long random string>
```

Create the bot with @BotFather; get your chat id by messaging the bot then reading
`https://api.telegram.org/bot<token>/getUpdates`.

## 2. `mediamtx.yml`

Under `pathDefaults:` (applies to all paths), add — replacing `SECRET` with the same
value as `MEDIAMTX_WEBHOOK_SECRET`, and the URL host/port with where Laravel listens
(same VM = 127.0.0.1):

```yaml
pathDefaults:
  runOnReady: >
    curl -fsS -m 4 -X POST http://127.0.0.1:8000/api/mediamtx/event
    -H "X-Webhook-Secret: SECRET"
    --data-urlencode "event=ready"
    --data-urlencode "path=$MTX_PATH"
    --data-urlencode "id=$MTX_SOURCE_ID"
  runOnNotReady: >
    curl -fsS -m 4 -X POST http://127.0.0.1:8000/api/mediamtx/event
    -H "X-Webhook-Secret: SECRET"
    --data-urlencode "event=notready"
    --data-urlencode "path=$MTX_PATH"
    --data-urlencode "id=$MTX_SOURCE_ID"
  runOnRead: >
    curl -fsS -m 4 -X POST http://127.0.0.1:8000/api/mediamtx/event
    -H "X-Webhook-Secret: SECRET"
    --data-urlencode "event=read"
    --data-urlencode "path=$MTX_PATH"
    --data-urlencode "id=$MTX_READER_ID"
  runOnUnread: >
    curl -fsS -m 4 -X POST http://127.0.0.1:8000/api/mediamtx/event
    -H "X-Webhook-Secret: SECRET"
    --data-urlencode "event=unread"
    --data-urlencode "path=$MTX_PATH"
    --data-urlencode "id=$MTX_READER_ID"
```

> Note: the exact env vars MediaMTX exposes to hooks vary by version. Confirm against
> your build's docs; `MTX_PATH` is always present. The client IP is not always exposed
> to read hooks — if absent, alerts show "IP unknown" (acceptable). To include it later,
> the webhook can resolve the IP from the MediaMTX API by reader id.

Restart MediaMTX after editing. Ensure a Laravel queue worker is running so Telegram
sends are processed.
