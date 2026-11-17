<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Continue with Google" (OpenID Connect, authorization code + PKCE), without an extra package. Only offered when
 * GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET are configured. The browser comes and goes through the storefront's /api
 * proxy, so the session cookie is the site's. An existing account with the same e-mail is signed in only when
 * Google says the address is verified; otherwise a customer account is created. Two-step verification still
 * applies: the member is sent to the sign-in panel's code step.
 */
final class GoogleAuthController extends Controller
{
    private const AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const USERINFO = 'https://openidconnect.googleapis.com/v1/userinfo';

    public static function enabled(): bool
    {
        return (string) config('services.google.client_id') !== '' && (string) config('services.google.client_secret') !== '';
    }

    public function providers()
    {
        return ['data' => ['google' => self::enabled()]];
    }

    public function redirect(Request $request)
    {
        abort_unless(self::enabled(), 404);
        $locale = in_array($request->query('locale'), ['fr', 'en'], true) ? $request->query('locale') : 'fr';
        $next = (string) $request->query('next', '');
        $state = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put('google_oauth', [
            'state' => $state,
            'verifier' => $verifier,
            'locale' => $locale,
            // Only a page of this site in this language, never another host.
            'next' => preg_match('~^/'.$locale.'(/[^/\\\\][^\\\\]*)?$~', $next) ? $next : null,
        ]);

        return redirect()->away(self::AUTHORIZE.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $this->callbackUrl(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
            'hl' => $locale,
        ]));
    }

    public function callback(Request $request)
    {
        abort_unless(self::enabled(), 404);
        $saved = $request->session()->pull('google_oauth');
        $locale = $saved['locale'] ?? 'fr';
        $site = rtrim((string) config('kora.storefront_url'), '/').'/'.$locale;
        $fail = fn (string $why) => redirect()->away($site.'/login?google='.$why);

        if (! $saved || ! is_string($request->query('state')) || ! hash_equals($saved['state'], $request->query('state'))) {
            return $fail('state');
        }
        if ($request->query('error') || ! is_string($request->query('code'))) {
            return $fail('cancelled');
        }
        $token = Http::asForm()->timeout(10)->post(self::TOKEN, [
            'code' => $request->query('code'),
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => $this->callbackUrl(),
            'grant_type' => 'authorization_code',
            'code_verifier' => $saved['verifier'],
        ]);
        if (! $token->successful() || ! $token->json('access_token')) {
            return $fail('token');
        }
        $profile = Http::withToken($token->json('access_token'))->timeout(10)->get(self::USERINFO);
        $email = strtolower((string) $profile->json('email'));
        if (! $profile->successful() || $email === '' || $profile->json('email_verified') !== true) {
            return $fail('email');
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            $user = new User;
            $user->name = Str::limit(trim((string) ($profile->json('name') ?: Str::before($email, '@'))), 100, '');
            $user->email = $email;
            // No password is known: a long random one; "Forgotten password" lets the member choose one later.
            $user->password = Str::random(64);
            $user->locale = $locale;
            $user->theme = 'system';
            $user->roles = ['client'];
            $user->email_verified_at = now();
            $user->save();
        }

        $next = $saved['next'] ?? '/'.$locale.'/account';
        if ($user->two_factor_confirmed_at) {
            // Same session keys as Fortify's two-step challenge, answered in the sign-in panel.
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => true]);

            return redirect()->away($site.'/login?challenge=1&next='.rawurlencode($next));
        }
        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();

        return redirect()->away(rtrim((string) config('kora.storefront_url'), '/').$next.(str_contains($next, '?') ? '&' : '?').'google=1');
    }

    private function callbackUrl(): string
    {
        return rtrim((string) config('kora.storefront_url'), '/').'/api/v1/auth/google/callback';
    }
}
