<?php

use App\Http\Middleware\ProtectAuthentication;
use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'home' => '/',
    'prefix' => 'api/v1/auth',
    'domain' => null,
    'middleware' => ['web', ProtectAuthentication::class],
    'auth_middleware' => 'auth',
    'views' => false,
    'limiters' => ['login' => 'login', 'two-factor' => 'two-factor'],
    'features' => [
        Features::registration(),
        Features::emailVerification(),
        Features::resetPasswords(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
    ],
];
