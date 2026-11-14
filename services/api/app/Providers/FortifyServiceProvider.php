<?php

namespace App\Providers;

use App\Actions\CreateNewUser;
use App\Actions\ResetUserPassword;
use App\Actions\UpdateUserPassword;
use App\Http\Responses\PasswordRecoveryResponse;
use App\Http\Responses\VerificationNotificationResponse;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\Sanctum;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmailVerificationNotificationSentResponse::class, VerificationNotificationResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, fn () => new PasswordRecoveryResponse);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, fn () => new PasswordRecoveryResponse);
        $this->app->bind(TwoFactorLoginResponse::class, function () {
            return new class implements TwoFactorLoginResponse
            {
                public function toResponse($request)
                {
                    $request->session()->put('mfa_verified', true);

                    return response()->json(['data' => $request->user()->publicProfile()]);
                }
            };
        });
    }

    public function boot(): void
    {
        // Livraison web par session uniquement ; pas de voie Bearer parallèle au MFA.
        Sanctum::getAccessTokenFromRequestUsing(fn () => null);
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        RateLimiter::for(
            'login',
            fn (Request $request) => [
                Limit::perMinute(5)->by(
                    hash('sha256', strtolower($request->input('email', '')).'|'.$request->ip()),
                ),
                Limit::perMinute(30)->by($request->ip()),
            ],
        );
        RateLimiter::for(
            'two-factor',
            fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id', $request->ip())),
        );
        ResetPassword::createUrlUsing(
            fn ($user, $token) => rtrim(env('STOREFRONT_URL', 'http://localhost:4321'), '/').
                '/'.
                $user->locale.
                '/reset-password?token='.
                urlencode($token).
                '&email='.
                urlencode($user->email),
        );
    }
}
