<?php

use App\Http\Controllers\ReferralsController;
use App\Http\Middleware\RequireRecentConfirmation;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('account/referral', [ReferralsController::class, 'mine']);
        Route::post('account/referral/join', [ReferralsController::class, 'join'])->middleware('throttle:10,1');
        Route::post('account/referral/payouts', [ReferralsController::class, 'requestPayout'])->middleware(
            'throttle:5,1',
        );
    });

    Route::prefix('operations/referral')
        ->middleware('auth:sanctum')
        ->group(function () {
            Route::get('programs', [ReferralsController::class, 'programs'])->middleware('permission:referral.read');
            Route::post('programs', [ReferralsController::class, 'createProgram'])->middleware(
                'permission:referral.manage',
            );
            Route::post('programs/{id}/activate', [ReferralsController::class, 'activate'])
                ->whereUuid('id')
                ->middleware(['permission:referral.manage', RequireRecentConfirmation::class]);
            Route::get('referrals', [ReferralsController::class, 'referrals'])->middleware('permission:referral.read');
            Route::post('referrals/{id}/review', [ReferralsController::class, 'review'])
                ->whereUuid('id')
                ->middleware('permission:referral.manage');
            Route::get('payouts', [ReferralsController::class, 'payouts'])->middleware('permission:finance.read');
            foreach (['approve' => 'approvePayout', 'reject' => 'rejectPayout'] as $path => $action) {
                Route::post('payouts/{id}/'.$path, [ReferralsController::class, $action])
                    ->whereUuid('id')
                    ->middleware(['permission:finance.write', RequireRecentConfirmation::class]);
            }
            Route::post('payouts/{id}/paid', [ReferralsController::class, 'paid'])
                ->whereUuid('id')
                ->middleware(['permission:finance.write', RequireRecentConfirmation::class]);
        });
});
