<?php

use App\Http\Controllers\SupplierOffersController as C;
use App\Http\Middleware\RequireRecentConfirmation;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public: paid placements, always in their own labelled block.
    Route::get('catalogue/sponsored', [C::class, 'sponsored'])->middleware('throttle:240,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::prefix('supplier-portal/{supplier}')
            ->whereUuid('supplier')
            ->middleware('throttle:60,1')
            ->group(function () {
                Route::get('plans', [C::class, 'portalPlans']);
                Route::get('subscriptions', [C::class, 'portalSubscriptions']);
                Route::post('subscriptions', [C::class, 'subscribe']);
                Route::post('subscriptions/{id}/accept', [C::class, 'accept'])->whereUuid('id');
                Route::post('subscriptions/{id}/cancel', [C::class, 'portalCancel'])->whereUuid('id');
                Route::post('subscriptions/{id}/renewal', [C::class, 'renewal'])->whereUuid('id');
            });

        Route::prefix('operations')->group(function () {
            Route::get('supplier-offers/settings', [C::class, 'settings'])->middleware('permission:suppliers.portal');
            // Turning paid offers on, and prices, are owner decisions with a recent identity confirmation.
            Route::put('supplier-offers/settings', [C::class, 'saveSettings'])->middleware([
                'permission:suppliers.plans',
                RequireRecentConfirmation::class,
            ]);
            Route::get('supplier-plans', [C::class, 'plans'])->middleware('permission:suppliers.portal');
            Route::post('supplier-plans', [C::class, 'savePlan'])->middleware([
                'permission:suppliers.plans',
                RequireRecentConfirmation::class,
            ]);
            Route::put('supplier-plans/{id}', [C::class, 'savePlan'])
                ->whereUuid('id')
                ->middleware(['permission:suppliers.plans', RequireRecentConfirmation::class]);
            Route::post('supplier-plans/{id}/status', [C::class, 'planStatus'])
                ->whereUuid('id')
                ->middleware(['permission:suppliers.plans', RequireRecentConfirmation::class]);
            Route::get('supplier-subscriptions', [C::class, 'subscriptions'])->middleware(
                'permission:suppliers.portal',
            );
            Route::post('suppliers/{id}/subscriptions', [C::class, 'propose'])
                ->whereUuid('id')
                ->middleware('permission:suppliers.plans');
            Route::post('supplier-subscriptions/{id}/consent', [C::class, 'recordConsent'])
                ->whereUuid('id')
                ->middleware('permission:suppliers.plans');
            Route::post('supplier-subscriptions/{id}/cancel', [C::class, 'teamCancel'])
                ->whereUuid('id')
                ->middleware('permission:suppliers.plans');
            Route::get('supplier-billing-requests', [C::class, 'requests'])->middleware('permission:suppliers.portal');
            Route::post('supplier-billing-requests/{id}/paid', [C::class, 'markPaid'])
                ->whereUuid('id')
                ->middleware(['permission:finance.write', RequireRecentConfirmation::class]);
            Route::post('supplier-billing-requests/{id}/cancel', [C::class, 'cancelRequest'])
                ->whereUuid('id')
                ->middleware('permission:suppliers.plans');
        });
    });
});
