<?php

use App\Http\Controllers\ManagePromotionsController;
use App\Http\Middleware\RequireRecentConfirmation;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/operations/promotions')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::get('delivery-offers', [ManagePromotionsController::class, 'index'])->middleware(
            'permission:promotions.read',
        );
        Route::post('delivery-offers', [ManagePromotionsController::class, 'save'])->middleware(
            'permission:promotions.manage',
        );
        Route::put('delivery-offers/{id}', [ManagePromotionsController::class, 'save'])
            ->whereUuid('id')
            ->middleware('permission:promotions.manage');
        // Activating commits a budget: recent password confirmation, like other money decisions.
        Route::post('delivery-offers/{id}/{action}', [ManagePromotionsController::class, 'transition'])
            ->whereUuid('id')
            ->whereIn('action', ['activate', 'pause', 'end'])
            ->middleware(['permission:promotions.manage', RequireRecentConfirmation::class]);
    });
