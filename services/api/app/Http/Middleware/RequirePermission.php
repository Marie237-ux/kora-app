<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = $request->user();
        abort_unless($user && in_array($permission, $user->permissions(), true), 403);
        if (
            $user->isStaff() &&
            (! $user->two_factor_confirmed_at || ! $request->hasSession() || ! $request->session()->get('mfa_verified'))
        ) {
            return response()->json(['error' => ['code' => 'MFA_REQUIRED', 'message' => __('api.MFA_REQUIRED')]], 403);
        }

        return $next($request);
    }
}
