<?php

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\LoginResponse as BaseLoginResponse;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class LoginResponse extends BaseLoginResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $user = $request->user();

        if ($user !== null && $user->isMonitoringOnly()) {
            return redirect()->route('monitor');
        }

        return parent::toResponse($request);
    }
}
