<?php

use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\ManageDeliveryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('delivery/zones', [DeliveryController::class, 'zones'])->middleware('throttle:60,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('account/addresses', [DeliveryController::class, 'addresses']);
        Route::post('account/addresses', [DeliveryController::class, 'createAddress'])->middleware('throttle:20,1');
        Route::put('account/addresses/{id}', [DeliveryController::class, 'updateAddress'])
            ->whereUuid('id')
            ->middleware('throttle:30,1');
        Route::post('account/addresses/{id}/archive', [DeliveryController::class, 'archiveAddress'])
            ->whereUuid('id')
            ->middleware('throttle:30,1');
        Route::post('cart/delivery-quotes', [DeliveryController::class, 'quote'])->middleware('throttle:30,1');
        Route::get('cart/delivery-quotes/{id}', [DeliveryController::class, 'showQuote'])->whereUuid('id');
        Route::post('cart/delivery-quotes/{id}/slot', [DeliveryController::class, 'slot'])
            ->whereUuid('id')
            ->middleware('throttle:30,1');
    });

    Route::prefix('operations/delivery')
        ->middleware('auth:sanctum')
        ->group(function () {
            Route::get('zones', [ManageDeliveryController::class, 'zones'])->middleware('permission:delivery.read');
            // Shipping between cities: fee and delay per origin / destination ("*" = any other city).
            Route::get('shipping-routes', [\App\Http\Controllers\ShippingRoutesController::class, 'index'])->middleware('permission:delivery.read');
            Route::post('shipping-routes', [\App\Http\Controllers\ShippingRoutesController::class, 'save'])->middleware('permission:delivery.settings');
            Route::put('shipping-routes/{id}', [\App\Http\Controllers\ShippingRoutesController::class, 'save'])
                ->whereUuid('id')
                ->middleware('permission:delivery.settings');
            Route::post('zones', [ManageDeliveryController::class, 'saveZone'])->middleware(
                'permission:delivery.settings',
            );
            Route::put('zones/{id}', [ManageDeliveryController::class, 'saveZone'])
                ->whereUuid('id')
                ->middleware('permission:delivery.settings');
            Route::get('collection-points', [ManageDeliveryController::class, 'points'])->middleware(
                'permission:delivery.read',
            );
            Route::post('collection-points', [ManageDeliveryController::class, 'savePoint'])->middleware(
                'permission:delivery.settings',
            );
            Route::put('collection-points/{id}', [ManageDeliveryController::class, 'savePoint'])
                ->whereUuid('id')
                ->middleware('permission:delivery.settings');
            Route::get('grids', [ManageDeliveryController::class, 'grids'])->middleware('permission:delivery.read');
            Route::post('grids', [ManageDeliveryController::class, 'createGrid'])->middleware(
                'permission:delivery.settings',
            );
            Route::put('grids/{id}', [ManageDeliveryController::class, 'updateGrid'])
                ->whereUuid('id')
                ->middleware('permission:delivery.settings');
            Route::post('grids/{id}/publish', [ManageDeliveryController::class, 'publishGrid'])
                ->whereUuid('id')
                ->middleware('permission:delivery.settings');
            Route::get('slot-rules', [ManageDeliveryController::class, 'rules'])->middleware(
                'permission:delivery.read',
            );
            Route::post('slot-rules', [ManageDeliveryController::class, 'saveRule'])->middleware(
                'permission:delivery.settings',
            );
            Route::put('slot-rules/{id}', [ManageDeliveryController::class, 'saveRule'])
                ->whereUuid('id')
                ->middleware('permission:delivery.settings');
            Route::get('slot-closures', [ManageDeliveryController::class, 'closures'])->middleware(
                'permission:delivery.read',
            );
            Route::post('slot-closures', [ManageDeliveryController::class, 'createClosure'])->middleware(
                'permission:delivery.settings',
            );
            Route::get('slots', [ManageDeliveryController::class, 'occupancy'])->middleware('permission:delivery.read');
        });
});
