<?php

use App\Http\Controllers\LoyaltyCheckoutController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Middleware\RequireRecentConfirmation;
use Illuminate\Support\Facades\Route;

Route::get('v1/loyalty/cards/{token}', [LoyaltyController::class, 'consult'])
    ->where('token', '[a-f0-9]{64}')
    ->middleware('throttle:30,1');
Route::prefix('v1/account/loyalty')
    ->middleware(['auth:sanctum', 'throttle:60,1'])
    ->group(function () {
        Route::get('', [LoyaltyController::class, 'show']);
        Route::get('redemption-policy', [LoyaltyCheckoutController::class, 'policy']);
        Route::post('checkout-quotes', [LoyaltyCheckoutController::class, 'quote']);
        Route::post('card', [LoyaltyController::class, 'enroll']);
        Route::post('card/rotate-qr', [LoyaltyController::class, 'rotate']);
        Route::get('movements', [LoyaltyController::class, 'movements']);
        Route::get('grants', [LoyaltyController::class, 'grants']);
    });
Route::prefix('v1/operations/loyalty')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::get('program', [LoyaltyController::class, 'settings'])->middleware('permission:loyalty.read');
        Route::get('redemption-policy', [LoyaltyCheckoutController::class, 'settings'])->middleware(
            'permission:loyalty.budget.read',
        );
        Route::get('redemption-policy/revisions', [LoyaltyCheckoutController::class, 'revisions'])->middleware(
            'permission:loyalty.budget.read',
        );
        Route::put('redemption-policy', [LoyaltyCheckoutController::class, 'save'])->middleware([
            'permission:loyalty.settings',
            RequireRecentConfirmation::class,
        ]);
        Route::get('program/revisions', [LoyaltyController::class, 'revisions'])->middleware('permission:loyalty.read');
        Route::put('program', [LoyaltyController::class, 'saveSettings'])->middleware([
            'permission:loyalty.settings',
            RequireRecentConfirmation::class,
        ]);
        Route::get('accounts/{id}', [LoyaltyController::class, 'manageShow'])
            ->whereUuid('id')
            ->middleware('permission:loyalty.read');
        Route::get('accounts/{id}/movements', [LoyaltyController::class, 'manageMovements'])
            ->whereUuid('id')
            ->middleware('permission:loyalty.read');
        Route::post('accounts/{id}/adjustments', [LoyaltyController::class, 'adjust'])
            ->whereUuid('id')
            ->middleware(['permission:loyalty.adjust', RequireRecentConfirmation::class]);
        Route::put('accounts/{id}/status', [LoyaltyController::class, 'status'])
            ->whereUuid('id')
            ->middleware(['permission:loyalty.adjust', RequireRecentConfirmation::class]);
        Route::post('refunds/{id}/lines', [LoyaltyController::class, 'allocateRefund'])
            ->whereUuid('id')
            ->middleware(['permission:loyalty.adjust', RequireRecentConfirmation::class]);
    });
