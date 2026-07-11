<?php

namespace App\Listeners;

use App\Jobs\SendTelegramMessage;
use App\Support\TelegramAlert;
use Carbon\Carbon;
use Spatie\Activitylog\Models\Activity;

class SecurityAlertListener
{
    private const FLOOD_CAP_PER_MINUTE = 30;

    public function handle(Activity $activity): void
    {
        if (! config('services.telegram.enabled') || ! config('services.telegram.security_alerts_enabled')) {
            return;
        }

        if ($activity->log_name !== 'security') {
            return;
        }

        $description = (string) $activity->description;
        if (! in_array($description, (array) config('services.telegram.security_events', []), true)) {
            return;
        }

        if (! TelegramAlert::floodCap('security', self::FLOOD_CAP_PER_MINUTE)) {
            return;
        }

        SendTelegramMessage::dispatch($this->format($activity, $description));
    }

    private function format(Activity $activity, string $description): string
    {
        [$emoji, $title] = match ($description) {
            'session.kicked' => ['🚫', 'Session kicked'],
            'ip.blocked.from_session' => ['⛔', 'IP blocked (from session)'],
            'ip.blocked' => ['⛔', 'IP blocked'],
            'ip.blocked.from_security_events' => ['⛔', 'IP blocked'],
            'ip.blocked.from_top_ips' => ['⛔', 'IP blocked'],
            'ip.unblocked' => ['✅', 'IP unblocked'],
            'token.read.denied.blocked_ip' => ['🛑', 'Read denied — blocked IP'],
            'token.read.denied.bad_referer' => ['🛑', 'Read denied — bad referrer'],
            default => ['🔔', $description],
        };

        $props = $activity->properties ?? collect();
        $get = fn (string $k) => (string) data_get($props, $k, '');

        $lines = ["{$emoji} <b>".TelegramAlert::esc($title).'</b>', ''];

        foreach ([
            'IP' => $get('ip'),
            'Stream' => $get('path'),
            'Referer' => $get('referer'),
            'Reason' => $get('reason'),
        ] as $label => $value) {
            if ($value !== '') {
                $lines[] = "<b>{$label}:</b> <code>".TelegramAlert::esc($value).'</code>';
            }
        }

        $causer = $activity->causer;
        if ($causer && isset($causer->username)) {
            $lines[] = '<b>By:</b> '.TelegramAlert::esc((string) $causer->username);
        }

        $when = Carbon::parse($activity->created_at)->timezone('Asia/Manila')->format('M d, Y h:i:s A');
        $lines[] = "🕒 <i>{$when}</i>";

        return implode("\n", $lines);
    }
}
