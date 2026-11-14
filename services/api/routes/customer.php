<?php

use App\Http\Controllers\AccountNotificationsController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\ProductRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('cart', [CartController::class, 'show'])->middleware('throttle:120,1');
    Route::post('cart/lines', [CartController::class, 'add'])->middleware('throttle:60,1');
    Route::put('cart/lines/{id}', [CartController::class, 'edit'])
        ->whereUuid('id')
        ->middleware('throttle:60,1');
    Route::delete('cart/lines/{id}', [CartController::class, 'remove'])
        ->whereUuid('id')
        ->middleware('throttle:60,1');
    Route::post('cart/accept-changes', [CartController::class, 'accept'])->middleware('throttle:30,1');
    Route::post('cart/merge', [CartController::class, 'merge'])->middleware(['auth:sanctum', 'throttle:15,1']);
    Route::get('cart/shares', [CartController::class, 'shares'])->middleware('throttle:60,1');
    Route::post('cart/shares', [CartController::class, 'share'])->middleware('throttle:15,1');
    Route::post('cart/shares/{id}/revoke', [CartController::class, 'revoke'])
        ->whereUuid('id')
        ->middleware('throttle:30,1');
    Route::get('cart-shares/{token}', [CartController::class, 'publicShare'])
        ->where('token', '[a-f0-9]{64}')
        ->middleware('throttle:30,1');
    Route::post('cart-shares/{token}/copy', [CartController::class, 'copy'])
        ->where('token', '[a-f0-9]{64}')
        ->middleware('throttle:15,1');
    Route::post('product-request-drafts', [ProductRequestController::class, 'create'])->middleware('throttle:15,1');
    Route::put('product-request-drafts/{id}', [ProductRequestController::class, 'update'])
        ->whereUuid('id')
        ->middleware('throttle:60,1');
    Route::get('product-requests/{id}', [ProductRequestController::class, 'show'])
        ->whereUuid('id')
        ->middleware('throttle:120,1');
    Route::post('product-requests/{id}/photos', [ProductRequestController::class, 'photo'])
        ->whereUuid('id')
        ->middleware('throttle:15,1');
    Route::get('product-requests/{id}/photos/{photoId}', [ProductRequestController::class, 'photoFile'])
        ->whereUuid(['id', 'photoId'])
        ->middleware('throttle:120,1');
    Route::post('product-requests/{id}/photos/{photoId}/remove', [ProductRequestController::class, 'removePhoto'])
        ->whereUuid(['id', 'photoId'])
        ->middleware('throttle:60,1');
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('notifications', [AccountNotificationsController::class, 'index']);
        Route::post('notifications/{id}/read', [AccountNotificationsController::class, 'read'])->whereUuid('id');
        Route::get('product-requests', [ProductRequestController::class, 'index']);
        Route::post('product-requests/{id}/submit', [ProductRequestController::class, 'submit'])
            ->whereUuid('id')
            ->middleware('throttle:10,1');
        Route::post('product-requests/{id}/withdraw', [ProductRequestController::class, 'withdraw'])->whereUuid('id');
        Route::get('product-requests/{id}/messages', [ProductRequestController::class, 'messages'])->whereUuid('id');
        Route::post('product-requests/{id}/messages', [ProductRequestController::class, 'message'])
            ->whereUuid('id')
            ->middleware('throttle:30,1');
        Route::get('product-requests/{id}/events', [ProductRequestController::class, 'events'])->whereUuid('id');
        Route::post('product-requests/{id}/alternative', [ProductRequestController::class, 'choose'])->whereUuid('id');
    });
});
