<?php

use App\Http\Controllers\CourierMissionsController;
use App\Http\Controllers\CouriersController;
use App\Http\Controllers\DeliveryMissionsController;
use App\Http\Controllers\DeliveryRoundsController;
use App\Http\Controllers\SupplierCollectionsController;
use App\Http\Middleware\RequireMissionAssignment;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public: the customer scans the courier's QR code. Minimal answer, rate limited, no session needed.
    Route::get('courier-verifications', [DeliveryMissionsController::class, 'verify'])->middleware('throttle:30,1');

    Route::prefix('operations')
        ->middleware('auth:sanctum')
        ->group(function () {
            Route::get('couriers', [CouriersController::class, 'index'])->middleware('permission:delivery.read');
            Route::post('couriers', [CouriersController::class, 'register'])->middleware(
                'permission:delivery.settings',
            );
            Route::put('couriers/{id}', [CouriersController::class, 'update'])
                ->whereNumber('id')
                ->middleware('permission:delivery.settings');
            Route::get('missions', [DeliveryMissionsController::class, 'index'])->middleware(
                'permission:delivery.read',
            );
            Route::get('missions/{id}', [DeliveryMissionsController::class, 'show'])
                ->whereUuid('id')
                ->middleware('permission:delivery.read');
            Route::get('missions/{id}/photos/{photoId}', [DeliveryMissionsController::class, 'photo'])
                ->whereUuid(['id', 'photoId'])
                ->middleware('permission:delivery.read');
            Route::post('orders/{id}/missions', [DeliveryMissionsController::class, 'create'])
                ->whereUuid('id')
                ->middleware('permission:delivery.assign');
            foreach (
                [
                    'assign' => 'assign',
                    'cancel' => 'cancel',
                    'code' => 'renewCode',
                    'incident-resolution' => 'resolveIncident',
                    'alternative/approve' => 'approveAlternative',
                    'alternative/reject' => 'rejectAlternative',
                ] as $path => $action
            ) {
                Route::post('missions/{id}/'.$path, [DeliveryMissionsController::class, $action])
                    ->whereUuid('id')
                    ->middleware('permission:delivery.assign');
            }
            Route::get('collections', [SupplierCollectionsController::class, 'index'])->middleware(
                'permission:delivery.read',
            );
            Route::get('collections/{id}', [SupplierCollectionsController::class, 'show'])
                ->whereUuid('id')
                ->middleware('permission:delivery.read');
            Route::post('purchases/{id}/collections', [SupplierCollectionsController::class, 'create'])
                ->whereUuid('id')
                ->middleware('permission:delivery.assign');
            foreach (['assign' => 'assign', 'cancel' => 'cancel', 'retry' => 'retry'] as $path => $action) {
                Route::post('collections/{id}/'.$path, [SupplierCollectionsController::class, $action])
                    ->whereUuid('id')
                    ->middleware('permission:delivery.assign');
            }
            Route::post('purchases/{id}/direct-collections', [SupplierCollectionsController::class, 'createDirect'])
                ->whereUuid('id')
                ->middleware('permission:delivery.assign');
            // A manager designated to go along receives at the shop in place of the courier.
            Route::post('collections/{id}/direct-receipt', [SupplierCollectionsController::class, 'directReceipt'])
                ->whereUuid('id')
                ->middleware(['permission:stock.receive', 'throttle:30,1']);
            // The depot's stock team, who received the goods with evidence, closes the collection.
            Route::post('collections/{id}/received', [SupplierCollectionsController::class, 'received'])
                ->whereUuid('id')
                ->middleware('permission:stock.receive');
            Route::get('rounds', [DeliveryRoundsController::class, 'index'])->middleware('permission:delivery.read');
            Route::get('rounds/{id}', [DeliveryRoundsController::class, 'show'])
                ->whereUuid('id')
                ->middleware('permission:delivery.read');
            Route::post('rounds', [DeliveryRoundsController::class, 'create'])->middleware(
                'permission:delivery.assign',
            );
            Route::put('rounds/{id}/missions', [DeliveryRoundsController::class, 'replan'])
                ->whereUuid('id')
                ->middleware('permission:delivery.assign');
            Route::put('rounds/{id}/stops', [DeliveryRoundsController::class, 'reorder'])
                ->whereUuid('id')
                ->middleware('permission:delivery.assign');
            Route::post('rounds/{id}/cancel', [DeliveryRoundsController::class, 'cancel'])
                ->whereUuid('id')
                ->middleware('permission:delivery.assign');
        });

    // Courier space (web/PWA on a phone): only the missions currently assigned to the signed-in courier.
    Route::prefix('courier')
        ->middleware(['auth:sanctum', 'permission:missions.own'])
        ->group(function () {
            Route::get('day', [DeliveryRoundsController::class, 'day']);
            Route::get('missions', [CourierMissionsController::class, 'index']);
            Route::get('missions/{id}', [CourierMissionsController::class, 'show'])
                ->whereUuid('id')
                ->middleware(RequireMissionAssignment::class);
            Route::get('collections', [SupplierCollectionsController::class, 'courierIndex']);
            Route::get('collections/{id}', [SupplierCollectionsController::class, 'courierShow'])
                ->whereUuid('id')
                ->middleware(RequireMissionAssignment::class.':supplier_collections');
            foreach (['accept', 'decline', 'collect', 'incident', 'drop'] as $action) {
                Route::post('collections/{id}/'.$action, [SupplierCollectionsController::class, $action])
                    ->whereUuid('id')
                    ->middleware([RequireMissionAssignment::class.':supplier_collections', 'throttle:30,1']);
            }
            Route::post('missions/{id}/photos', [CourierMissionsController::class, 'photo'])
                ->whereUuid('id')
                ->middleware([RequireMissionAssignment::class, 'throttle:30,1']);
            Route::post('missions/{id}/shop-photos', [CourierMissionsController::class, 'shopPhoto'])
                ->whereUuid('id')
                ->middleware([RequireMissionAssignment::class, 'throttle:30,1']);
            Route::post('missions/{id}/shop-receipts', [CourierMissionsController::class, 'shopReceipt'])
                ->whereUuid('id')
                ->middleware([RequireMissionAssignment::class, 'throttle:30,1']);
            foreach (['accept', 'decline', 'pickup', 'incident', 'handover', 'alternative'] as $action) {
                Route::post('missions/{id}/'.$action, [CourierMissionsController::class, $action])
                    ->whereUuid('id')
                    ->middleware([RequireMissionAssignment::class, 'throttle:30,1']);
            }
        });
});
