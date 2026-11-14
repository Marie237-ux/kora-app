<?php

use App\Http\Controllers\CustomerDocumentsController;
use App\Http\Controllers\ManageDocumentsController;
use App\Http\Middleware\RequireRecentConfirmation;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::get('orders/{id}/documents', [CustomerDocumentsController::class, 'index'])->whereUuid('id');
        Route::get('orders/{id}/documents/{documentId}/pdf', [CustomerDocumentsController::class, 'pdf'])
            ->whereUuid(['id', 'documentId'])
            ->middleware('throttle:30,1');

        Route::prefix('operations')->group(function () {
            Route::get('settings/billing', [ManageDocumentsController::class, 'identity'])->middleware(
                'permission:billing.read',
            );
            Route::put('settings/billing', [ManageDocumentsController::class, 'saveIdentity'])->middleware([
                'permission:billing.settings',
                RequireRecentConfirmation::class,
            ]);
            Route::get('customer-documents', [ManageDocumentsController::class, 'index'])->middleware(
                'permission:billing.read',
            );
            Route::post('orders/{id}/invoice', [ManageDocumentsController::class, 'createInvoice'])
                ->whereUuid('id')
                ->middleware('permission:billing.issue');
            Route::post('refunds/{id}/credit-note', [ManageDocumentsController::class, 'createCreditNote'])
                ->whereUuid('id')
                ->middleware('permission:billing.issue');
            Route::post('customer-documents/{id}/issue', [ManageDocumentsController::class, 'issue'])
                ->whereUuid('id')
                ->middleware(['permission:billing.issue', RequireRecentConfirmation::class]);
            Route::get('customer-documents/{id}/pdf', [ManageDocumentsController::class, 'pdf'])
                ->whereUuid('id')
                ->middleware(['permission:billing.read', 'throttle:30,1']);
        });
    });
