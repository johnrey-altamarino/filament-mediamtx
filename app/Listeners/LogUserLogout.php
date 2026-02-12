<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Logout;

class LogUserLogout
{
    public function handle(Logout $event): void
    {
        activity('auth')
            ->causedBy($event->user)
            ->withProperties([
                'username' => $event->user?->username,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ])
            ->log('Logged out');
    }
}
