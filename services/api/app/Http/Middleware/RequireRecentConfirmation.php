<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class RequireRecentConfirmation
{
    public function handle(Request $request, Closure $next)
    {
        $confirmed = $request->hasSession() ? $request->session()->get('auth.password_confirmed_at') : null;
        $age = is_int($confirmed) ? now()->timestamp - $confirmed : null;
        if ($age === null || $age < 0 || $age > 900) {
            return response()->json(
                [
                    'error' => [
                        'code' => 'REAUTHENTICATION_REQUIRED',
                        'message' => app()->getLocale() === 'fr'
                                ? 'Confirmez votre mot de passe pour cette opération sensible.'
                                : 'Confirm your password for this sensitive operation.',
                    ],
                ],
                403,
            );
        }

        return $next($request);
    }
}
