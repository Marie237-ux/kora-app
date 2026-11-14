<?php

use App\Http\Controllers\ManageOrdersController;
use App\Http\Controllers\OrderPreparationController;
use App\Http\Controllers\OrderPurchasesController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\OrderSupplyController;
use App\Http\Controllers\PaymentNotificationController;
use App\Http\Middleware\RequireRecentConfirmation;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Called by the provider, server to server: no session, signature checked, then verified through its API.
    Route::post('payments/monetbil/notify', [PaymentNotificationController::class, 'notify'])->middleware(
        'throttle:120,1',
    );

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('orders', [OrdersController::class, 'index']);
        Route::post('orders', [OrdersController::class, 'create'])->middleware('throttle:20,1');
        Route::get('orders/{id}', [OrdersController::class, 'show'])->whereUuid('id');
        Route::post('orders/{id}/supply-cases/{caseId}/choice', [OrderSupplyController::class, 'choose'])
            ->whereUuid(['id', 'caseId'])
            ->middleware('throttle:20,1');
        Route::post('orders/{id}/payments', [OrdersController::class, 'pay'])
            ->whereUuid('id')
            ->middleware('throttle:10,1');
        Route::post('orders/{id}/payments/{attemptId}/check', [OrdersController::class, 'check'])
            ->whereUuid(['id', 'attemptId'])
            ->middleware('throttle:20,1');
    });

    Route::prefix('operations')
        ->middleware('auth:sanctum')
        ->group(function () {
            Route::get('orders/{id}/procurement', [OrderPurchasesController::class, 'show'])
                ->whereUuid('id')
                ->middleware('permission:purchases.read');
            Route::post('orders/{id}/lines/{lineId}/purchase-allocations', [OrderPurchasesController::class, 'assign'])
                ->whereUuid(['id', 'lineId'])
                ->middleware('permission:purchases.write');
            Route::post('purchase-allocations/{id}/receipts', [OrderPurchasesController::class, 'receive'])
                ->whereUuid('id')
                ->middleware('permission:purchases.write');
            Route::post('purchase-allocations/{id}/release', [OrderPurchasesController::class, 'releasePurchase'])
                ->whereUuid('id')
                ->middleware('permission:purchases.write');
            Route::post('receipt-allocations/{id}/release', [OrderPurchasesController::class, 'releaseReceipt'])
                ->whereUuid('id')
                ->middleware('permission:purchases.write');
            Route::post('orders/{id}/lines/{lineId}/supplier-reservations', [
                OrderSupplyController::class,
                'requestReservation',
            ])
                ->whereUuid(['id', 'lineId'])
                ->middleware('permission:purchases.write');
            Route::post('orders/{id}/lines/{lineId}/unavailable', [OrderSupplyController::class, 'unavailable'])
                ->whereUuid(['id', 'lineId'])
                ->middleware('permission:purchases.write');
            Route::get('preparations', [OrderPreparationController::class, 'index'])->middleware(
                'permission:stock.read',
            );
            Route::get('orders/{id}/preparation', [OrderPreparationController::class, 'show'])
                ->whereUuid('id')
                ->middleware('permission:stock.read');
            Route::post('orders/{id}/preparation/picks', [OrderPreparationController::class, 'pick'])
                ->whereUuid('id')
                ->middleware('permission:stock.write');
            Route::post('orders/{id}/preparation/picks/{pickId}/replace', [
                OrderPreparationController::class,
                'replace',
            ])
                ->whereUuid(['id', 'pickId'])
                ->middleware('permission:stock.write');
            Route::post('orders/{id}/preparation/ready', [OrderPreparationController::class, 'ready'])
                ->whereUuid('id')
                ->middleware('permission:stock.write');
            Route::post('orders/{id}/preparation/anomalies', [OrderPreparationController::class, 'report'])
                ->whereUuid('id')
                ->middleware('permission:stock.write');
            Route::post('preparation-anomalies/{id}/resolve', [OrderPreparationController::class, 'resolve'])
                ->whereUuid('id')
                ->middleware('permission:stock.write');
            Route::get('orders', [ManageOrdersController::class, 'index'])->middleware('permission:orders.read');
            Route::get('orders/{id}', [ManageOrdersController::class, 'show'])
                ->whereUuid('id')
                ->middleware('permission:orders.read');
            Route::get('payment-incidents', [ManageOrdersController::class, 'incidents'])->middleware(
                'permission:payments.read',
            );
            Route::post('payment-incidents/{id}/resolve', [ManageOrdersController::class, 'resolveIncident'])
                ->whereUuid('id')
                ->middleware(['permission:payments.refund', RequireRecentConfirmation::class]);
            Route::get('refunds', [ManageOrdersController::class, 'refunds'])->middleware('permission:payments.read');
            Route::post('orders/{id}/refunds', [ManageOrdersController::class, 'requestRefund'])
                ->whereUuid('id')
                ->middleware(['permission:payments.refund', RequireRecentConfirmation::class]);
            Route::post('refunds/{id}/verify', [ManageOrdersController::class, 'verifyRefund'])
                ->whereUuid('id')
                ->middleware(['permission:payments.refund', RequireRecentConfirmation::class]);
            Route::post('refunds/{id}/cancel', [ManageOrdersController::class, 'cancelRefund'])
                ->whereUuid('id')
                ->middleware('permission:payments.refund');
            Route::post('payments/{attemptId}/check', [ManageOrdersController::class, 'check'])
                ->whereUuid('attemptId')
                ->middleware(['permission:payments.reconcile', 'throttle:30,1']);
        });
});
