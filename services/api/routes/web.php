<?php

use App\Http\Controllers\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'KORA API', 'version' => 'v1']));

// "Continue with Google": web routes (the session must exist when Google sends the visitor back), reached through
// the storefront's /api proxy. Offered only when GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET are set.
Route::get('api/v1/auth/providers', [GoogleAuthController::class, 'providers']);
Route::get('api/v1/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:20,1');
Route::get('api/v1/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:20,1');
