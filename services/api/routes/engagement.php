<?php

use App\Http\Controllers\EngagementController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('products/{id}/reviews', [EngagementController::class, 'productReviews'])
        ->whereUuid('id')
        ->middleware('throttle:120,1');
    Route::get('notification-preferences/unsubscribe', [EngagementController::class, 'unsubscribe'])->middleware(
        'throttle:30,1',
    );

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('account/reviews', [EngagementController::class, 'myReviews']);
        Route::post('account/reviews', [EngagementController::class, 'writeReview'])->middleware('throttle:20,1');
        Route::post('account/reviews/{id}/photos', [EngagementController::class, 'reviewPhoto'])
            ->whereUuid('id')
            ->middleware('throttle:20,1');
        Route::post('reviews/{id}/report', [EngagementController::class, 'report'])
            ->whereUuid('id')
            ->middleware('throttle:10,1');
        Route::get('account/alerts', [EngagementController::class, 'alerts']);
        Route::post('account/alerts', [EngagementController::class, 'createAlert'])->middleware('throttle:30,1');
        Route::post('account/alerts/{id}/cancel', [EngagementController::class, 'cancelAlert'])->whereUuid('id');
        Route::post('account/viewed', [EngagementController::class, 'viewed'])->middleware('throttle:120,1');
        Route::get('account/viewed', [EngagementController::class, 'history']);
        Route::put('account/viewed/settings', [EngagementController::class, 'historySettings']);
        Route::delete('account/viewed', [EngagementController::class, 'clearHistory']);
        Route::post('account/buy-again', [EngagementController::class, 'buyAgain'])->middleware('throttle:30,1');
        Route::get('account/inbox', [EngagementController::class, 'inbox']);
        Route::post('account/inbox/{id}/read', [EngagementController::class, 'read'])->whereUuid('id');
        Route::get('account/notification-preferences', [EngagementController::class, 'preferences']);
        Route::put('account/notification-preferences', [EngagementController::class, 'setPreferences']);
        Route::post('account/push-subscriptions', [EngagementController::class, 'pushSubscribe'])->middleware(
            'throttle:10,1',
        );
    });

    Route::prefix('operations')
        ->middleware('auth:sanctum')
        ->group(function () {
            Route::get('reviews', [EngagementController::class, 'moderationQueue'])->middleware(
                'permission:reviews.moderate',
            );
            Route::post('reviews/{id}/moderation', [EngagementController::class, 'moderate'])
                ->whereUuid('id')
                ->middleware('permission:reviews.moderate');
            Route::post('reviews/{id}/reply', [EngagementController::class, 'reply'])
                ->whereUuid('id')
                ->middleware('permission:reviews.moderate');
            Route::post('review-reports/{id}/handle', [EngagementController::class, 'handleReport'])
                ->whereUuid('id')
                ->middleware('permission:reviews.moderate');
        });
});
