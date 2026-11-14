<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class ProtectAuthentication
{
    public function handle(Request $request, Closure $next)
    {
        if (
            $request->isMethod('POST') &&
            ! in_array($request->route()?->getName(), ['login.store', 'two-factor.login.store'], true)
        ) {
            $key = 'auth-actions:'.hash('sha256', $request->ip());
            abort_if(RateLimiter::tooManyAttempts($key, 20), 429);
            RateLimiter::hit($key, 60);
        }
        $user = $request->user();
        if ($user?->isStaff() && $request->routeIs('two-factor.disable')) {
            abort(403);
        }
        $enrollmentRecovery =
            $request->routeIs('two-factor.recovery-codes') && $request->session()->get('mfa_enrolling') === $user?->id;
        if (
            $user?->two_factor_confirmed_at &&
            $request->routeIs('two-factor.*') &&
            ! $request->routeIs('two-factor.login*') &&
            ! $request->session()->get('mfa_verified') &&
            ! $enrollmentRecovery
        ) {
            abort(403);
        }
        $enrolling = $user && ! $user->two_factor_confirmed_at && $request->routeIs('two-factor.enable');
        $response = $next($request);
        if ($enrolling && $response->isSuccessful()) {
            $request->session()->put('mfa_enrolling', $user->id);
        }
        if ($enrollmentRecovery && $response->isSuccessful()) {
            $request->session()->forget('mfa_enrolling');
        }

        return $response;
    }
}
