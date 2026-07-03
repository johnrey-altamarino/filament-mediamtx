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
