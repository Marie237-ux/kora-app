<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiContext
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->header('X-Kora-Locale', $request->query('locale', 'fr'));
        app()->setLocale(in_array($locale, ['fr', 'en'], true) ? $locale : 'fr');
        $request->attributes->set('request_id', (string) Str::uuid());
        $response = $next($request);
        $response->headers->set('X-Request-ID', $request->attributes->get('request_id'));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
