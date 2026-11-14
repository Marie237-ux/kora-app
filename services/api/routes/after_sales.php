<?php

use App\Http\Controllers\AfterSalesController;
use App\Http\Controllers\AfterSalesRepairsController;
use App\Http\Controllers\AfterSalesTripsController;
use App\Http\Controllers\ManageAfterSalesController;
use App\Http\Middleware\RequireMissionAssignment;
use App\Http\Middleware\RequireRecentConfirmation;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Customer: "My purchases and warranties" and their own after-sales cases.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('account/purchases', [AfterSalesController::class, 'purchases']);
        Route::get('account/after-sales', [AfterSalesController::class, 'index']);
        Route::post('account/after-sales', [AfterSalesController::class, 'open'])->middleware('throttle:10,1');
        Route::get('account/after-sales/{id}', [AfterSalesController::class, 'show'])->whereUuid('id');
        Route::post('account/after-sales/{id}/messages', [AfterSalesController::class, 'message'])
            ->whereUuid('id')
            ->middleware('throttle:30,1');
        Route::post('account/after-sales/{id}/attachments', [AfterSalesController::class, 'attach'])
            ->whereUuid('id')
            ->middleware('throttle:20,1');
        Route::post('account/after-sales/{id}/decision', [AfterSalesController::class, 'decide'])
            ->whereUuid('id')
            ->middleware('throttle:10,1');
    });

    Route::prefix('operations')
        ->middleware('auth:sanctum')
        ->group(function () {
            Route::get('after-sales', [ManageAfterSalesController::class, 'index'])->middleware(
                'permission:support.read',
            );
            Route::get('after-sales/{id}', [ManageAfterSalesController::class, 'show'])
                ->whereUuid('id')
                ->middleware('permission:support.read');
            Route::get('after-sales/{id}/attachments/{messageId}', [ManageAfterSalesController::class, 'attachment'])
                ->whereUuid(['id', 'messageId'])
                ->middleware('permission:support.read');
            foreach (
                [
                    'messages' => 'message',
                    'assign' => 'assign',
                    'proposal' => 'propose',
                    'reject' => 'reject',
                    'resolve' => 'resolve',
                ] as $path => $action
            ) {
                Route::post('after-sales/{id}/'.$path, [ManageAfterSalesController::class, $action])
                    ->whereUuid('id')
                    ->middleware('permission:support.write');
            }
            Route::post('after-sales/{id}/refund', [ManageAfterSalesController::class, 'refund'])
                ->whereUuid('id')
                ->middleware(['permission:payments.refund', RequireRecentConfirmation::class]);
            Route::post('after-sales/{id}/returns', [ManageAfterSalesController::class, 'receiveReturn'])
                ->whereUuid('id')
                ->middleware('permission:stock.receive');
            Route::get('after-sales-trips', [AfterSalesTripsController::class, 'index'])->middleware(
                'permission:delivery.read',
            );
            Route::post('after-sales/{id}/pickup-trips', [AfterSalesTripsController::class, 'pickup'])
                ->whereUuid('id')
                ->middleware('permission:delivery.assign');
            // The stock team issues the exchange item from stock for the case before a courier carries it.
            Route::post('after-sales/{id}/exchange-shipments', [AfterSalesTripsController::class, 'exchange'])
                ->whereUuid('id')
                ->middleware('permission:stock.write');
            foreach (['assign', 'cancel'] as $action) {
                Route::post('after-sales-trips/{id}/'.$action, [AfterSalesTripsController::class, $action])
                    ->whereUuid('id')
                    ->middleware('permission:delivery.assign');
            }
            Route::post('after-sales-returns/{id}/control', [ManageAfterSalesController::class, 'control'])
                ->whereUuid('id')
                ->middleware('permission:stock.write');
            // Repairs of the customer's own unit, and defective units back to their supplier.
            Route::get('after-sales-repairs', [AfterSalesRepairsController::class, 'index'])->middleware(
                'permission:support.read',
            );
            Route::post('after-sales/{id}/repairs', [AfterSalesRepairsController::class, 'open'])
                ->whereUuid('id')
                ->middleware('permission:stock.write');
            Route::post('after-sales-repairs/{id}/send', [AfterSalesRepairsController::class, 'send'])
                ->whereUuid('id')
                ->middleware('permission:stock.write');
            Route::post('after-sales-repairs/{id}/back', [AfterSalesRepairsController::class, 'back'])
                ->whereUuid('id')
                ->middleware('permission:stock.write');
            Route::post('after-sales/{id}/repair-returns', [AfterSalesRepairsController::class, 'returnTrip'])
                ->whereUuid('id')
                ->middleware('permission:stock.write');
            Route::post('after-sales-returns/{id}/supplier-return', [AfterSalesRepairsController::class, 'toSupplier'])
                ->whereUuid('id')
                ->middleware('permission:stock.write');
            Route::post('after-sales-supplier-returns/{id}/decision', [AfterSalesRepairsController::class, 'decision'])
                ->whereUuid('id')
                ->middleware('permission:purchases.write');
        });

    // Courier: after-sales trips assigned to them.
    Route::prefix('courier')
        ->middleware(['auth:sanctum', 'permission:missions.own'])
        ->group(function () {
            Route::get('trips', [AfterSalesTripsController::class, 'courierIndex']);
            Route::get('trips/{id}', [AfterSalesTripsController::class, 'courierShow'])
                ->whereUuid('id')
                ->middleware(RequireMissionAssignment::class.':after_sales_trips');
            foreach (['take', 'drop', 'handover'] as $action) {
                Route::post('trips/{id}/'.$action, [AfterSalesTripsController::class, $action])
                    ->whereUuid('id')
                    ->middleware([RequireMissionAssignment::class.':after_sales_trips', 'throttle:30,1']);
            }
        });
});
