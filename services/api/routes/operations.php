<?php

use App\Http\Controllers\InternalNotificationsController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ManageProductRequestsController;
use App\Http\Controllers\ManageSearchController;
use App\Http\Controllers\OutboxController;
use App\Http\Controllers\PayableMonitoringController;
use App\Http\Controllers\PayablesController;
use App\Http\Controllers\PrivateDocumentController;
use App\Http\Controllers\PurchasingController;
use App\Http\Controllers\SupplierAvailabilityController;
use App\Http\Controllers\SupplyContractController;
use App\Http\Middleware\RequireRecentConfirmation;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/operations')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::get('product-request-metrics', [ManageProductRequestsController::class, 'metrics'])->middleware(
            'permission:requests.read',
        );
        Route::get('search/synonyms', [ManageSearchController::class, 'index'])->middleware(
            'permission:catalogue.read',
        );
        Route::post('search/synonyms', [ManageSearchController::class, 'save'])->middleware(
            'permission:catalogue.write',
        );
        Route::put('search/synonyms/{id}', [ManageSearchController::class, 'save'])
            ->whereUuid('id')
            ->middleware('permission:catalogue.write');
        Route::get('search/misses', [ManageSearchController::class, 'misses'])->middleware('permission:catalogue.read');
        Route::get('product-request-groups', [ManageProductRequestsController::class, 'groups'])->middleware(
            'permission:requests.read',
        );
        Route::post('product-request-groups', [ManageProductRequestsController::class, 'createGroup'])->middleware(
            'permission:requests.write',
        );
        Route::get('product-requests', [ManageProductRequestsController::class, 'index'])->middleware(
            'permission:requests.read',
        );
        Route::get('product-requests/{id}', [ManageProductRequestsController::class, 'show'])
            ->whereUuid('id')
            ->middleware('permission:requests.read');
        Route::post('product-requests/{id}/state', [ManageProductRequestsController::class, 'transition'])
            ->whereUuid('id')
            ->middleware('permission:requests.write');
        Route::post('product-requests/{id}/assignment', [ManageProductRequestsController::class, 'assign'])
            ->whereUuid('id')
            ->middleware('permission:requests.write');
        Route::get('product-requests/{id}/messages', [ManageProductRequestsController::class, 'messages'])
            ->whereUuid('id')
            ->middleware('permission:requests.read');
        Route::post('product-requests/{id}/messages', [ManageProductRequestsController::class, 'message'])
            ->whereUuid('id')
            ->middleware('permission:requests.write');
        Route::get('product-requests/{id}/events', [ManageProductRequestsController::class, 'events'])
            ->whereUuid('id')
            ->middleware('permission:requests.read');
        Route::get('product-requests/{id}/candidates', [ManageProductRequestsController::class, 'candidates'])
            ->whereUuid('id')
            ->middleware('permission:requests.source');
        Route::post('product-requests/{id}/candidates', [ManageProductRequestsController::class, 'candidate'])
            ->whereUuid('id')
            ->middleware('permission:requests.source');
        Route::post('product-requests/{id}/offer', [ManageProductRequestsController::class, 'linkOffer'])
            ->whereUuid('id')
            ->middleware('permission:requests.write');
        Route::post('product-requests/{id}/product-draft', [ManageProductRequestsController::class, 'createProduct'])
            ->whereUuid('id')
            ->middleware('permission:requests.write');
        Route::get('product-requests/{id}/photos/{photoId}', [ManageProductRequestsController::class, 'photo'])
            ->whereUuid(['id', 'photoId'])
            ->middleware('permission:requests.read');
        Route::get('settings/payable-reminders', [PayableMonitoringController::class, 'policy'])->middleware(
            'permission:payables.settings',
        );
        Route::put('settings/payable-reminders', [PayableMonitoringController::class, 'savePolicy'])->middleware(
            'permission:payables.settings',
        );
        Route::get('payables/alerts', [PayableMonitoringController::class, 'index'])->middleware(
            'permission:purchases.read',
        );
        Route::get('payables/alerts/{id}', [PayableMonitoringController::class, 'show'])
            ->whereUuid('id')
            ->middleware('permission:purchases.read');
        Route::get('payables/forecast', [PayableMonitoringController::class, 'forecast'])->middleware(
            'permission:purchases.read',
        );
        Route::get('notifications', [InternalNotificationsController::class, 'index'])->middleware(
            'permission:purchases.read',
        );
        Route::post('notifications/{id}/read', [InternalNotificationsController::class, 'markRead'])
            ->whereUuid('id')
            ->middleware('permission:purchases.read');
        Route::get('system/outbox', [OutboxController::class, 'index'])->middleware('permission:system.read');
        Route::get('system/outbox/{id}/attempts', [OutboxController::class, 'attempts'])
            ->whereUuid('id')
            ->middleware('permission:system.read');
        Route::post('system/outbox/{id}/retry', [OutboxController::class, 'retry'])
            ->whereUuid('id')
            ->middleware(['permission:system.retry', RequireRecentConfirmation::class]);
        Route::post('documents', [PrivateDocumentController::class, 'store'])->middleware([
            'permission:documents.write',
            'throttle:20,1',
        ]);
        Route::get('documents/{id}', [PrivateDocumentController::class, 'show'])
            ->whereUuid('id')
            ->middleware('permission:documents.read');
        Route::get('documents/{id}/download', [PrivateDocumentController::class, 'download'])
            ->whereUuid('id')
            ->middleware('permission:documents.read');
        Route::post('documents/{id}/rescan', [PrivateDocumentController::class, 'rescan'])
            ->whereUuid('id')
            ->middleware(['permission:documents.write', 'throttle:20,1']);

        Route::get('contracts', [SupplyContractController::class, 'index'])->middleware('permission:purchases.read');
        Route::post('contracts', [SupplyContractController::class, 'store'])->middleware('permission:purchases.write');
        Route::get('contracts/{id}', [SupplyContractController::class, 'show'])
            ->whereUuid('id')
            ->middleware('permission:purchases.read');
        Route::put('contracts/{id}', [SupplyContractController::class, 'update'])
            ->whereUuid('id')
            ->middleware('permission:purchases.write');
        Route::get('contracts/{id}/revisions', [SupplyContractController::class, 'revisions'])
            ->whereUuid('id')
            ->middleware('permission:purchases.read');
        Route::post('contracts/{id}/{action}', [SupplyContractController::class, 'transition'])
            ->whereUuid('id')
            ->whereIn('action', ['review', 'approve', 'accept', 'terminate'])
            ->middleware('permission:purchases.write');

        Route::get('purchases', [PurchasingController::class, 'index'])->middleware('permission:purchases.read');
        Route::post('purchases', [PurchasingController::class, 'store'])->middleware('permission:purchases.write');
        Route::get('purchases/{id}', [PurchasingController::class, 'show'])
            ->whereUuid('id')
            ->middleware('permission:purchases.read');
        Route::post('purchases/{id}/confirm', [PurchasingController::class, 'confirm'])
            ->whereUuid('id')
            ->middleware('permission:purchases.write');
        Route::post('purchases/{id}/receipts', [PurchasingController::class, 'receive'])
            ->whereUuid('id')
            ->middleware('permission:stock.receive');
        Route::get('purchases/{id}/receipts', [PurchasingController::class, 'receipts'])
            ->whereUuid('id')
            ->middleware('permission:stock.receive');
        Route::post('purchases/{id}/consignment-statements', [PurchasingController::class, 'consignmentStatement'])
            ->whereUuid('id')
            ->middleware(['permission:finance.write', RequireRecentConfirmation::class]);
        Route::get('purchase-invoices', [PurchasingController::class, 'invoices'])->middleware(
            'permission:purchases.read',
        );
        Route::post('purchase-invoices', [PurchasingController::class, 'storeInvoice'])->middleware(
            'permission:purchases.write',
        );
        Route::get('purchase-invoices/{id}', [PurchasingController::class, 'showInvoice'])
            ->whereUuid('id')
            ->middleware('permission:purchases.read');
        Route::put('purchase-invoices/{id}', [PurchasingController::class, 'correctInvoice'])
            ->whereUuid('id')
            ->middleware('permission:purchases.write');
        Route::post('purchase-invoices/{id}/cancel-registration', [PurchasingController::class, 'cancelRegistration'])
            ->whereUuid('id')
            ->middleware('permission:purchases.write');
        Route::get('purchase-invoices/{id}/revisions', [PurchasingController::class, 'invoiceHistory'])
            ->whereUuid('id')
            ->middleware('permission:purchases.read');
        Route::post('receipt-lines/{id}/approve-overage', [PurchasingController::class, 'approveOverage'])
            ->whereUuid('id')
            ->middleware('permission:contracts.approve');
        Route::post('purchase-invoices/{id}/approve', [PurchasingController::class, 'approveInvoice'])
            ->whereUuid('id')
            ->middleware(['permission:finance.write', RequireRecentConfirmation::class]);

        Route::get('inventory/warehouses', [InventoryController::class, 'warehouses'])->middleware(
            'permission:stock.read',
        );
        Route::post('inventory/warehouses', [InventoryController::class, 'createWarehouse'])->middleware(
            'permission:stock.write',
        );
        Route::get('inventory/locations', [InventoryController::class, 'locations'])->middleware(
            'permission:stock.read',
        );
        Route::post('inventory/locations', [InventoryController::class, 'createLocation'])->middleware(
            'permission:stock.write',
        );
        Route::get('inventory/profiles/{variantId}', [InventoryController::class, 'profile'])
            ->whereUuid('variantId')
            ->middleware('permission:inventory.profile.read');
        Route::put('inventory/profiles/{variantId}', [InventoryController::class, 'saveProfile'])
            ->whereUuid('variantId')
            ->middleware('permission:inventory.profile.write');
        Route::get('settings/inventory', [InventoryController::class, 'settings'])->middleware(
            'permission:inventory.settings',
        );
        Route::put('settings/inventory', [InventoryController::class, 'saveSettings'])->middleware(
            'permission:inventory.settings',
        );
        Route::get('inventory/balances', [InventoryController::class, 'balances'])->middleware('permission:stock.read');
        Route::get('inventory/units', [InventoryController::class, 'units'])->middleware('permission:stock.read');
        Route::get('inventory/movements', [InventoryController::class, 'movements'])->middleware(
            'permission:stock.read',
        );
        Route::get('inventory/receivable-purchases', [InventoryController::class, 'receivablePurchases'])->middleware(
            'permission:stock.receive',
        );
        Route::get('inventory/offers/{id}/plan', [InventoryController::class, 'plan'])
            ->whereUuid('id')
            ->middleware('permission:stock.read');
        Route::post('inventory/reservations', [InventoryController::class, 'reserve'])->middleware(
            'permission:stock.write',
        );
        Route::post('inventory/reservations/{id}/release', [InventoryController::class, 'release'])
            ->whereUuid('id')
            ->middleware('permission:stock.write');
        Route::post('inventory/reservations/{id}/issue', [InventoryController::class, 'issue'])
            ->whereUuid('id')
            ->middleware(['permission:finance.write', RequireRecentConfirmation::class]);
        Route::post('inventory/transfers', [InventoryController::class, 'move'])->middleware('permission:stock.write');
        Route::post('inventory/adjustments', [InventoryController::class, 'adjust'])->middleware(
            'permission:stock.adjust',
        );
        Route::post('inventory/counts', [InventoryController::class, 'count'])->middleware('permission:stock.adjust');
        Route::post('inventory/supplier-returns', [InventoryController::class, 'supplierReturn'])->middleware(
            'permission:stock.write',
        );

        Route::get('supplier-availability/{offerId}', [SupplierAvailabilityController::class, 'show'])
            ->whereUuid('offerId')
            ->middleware('permission:purchases.read');
        Route::put('supplier-availability/{offerId}', [SupplierAvailabilityController::class, 'save'])
            ->whereUuid('offerId')
            ->middleware('permission:purchases.write');
        Route::get('supplier-reservations', [SupplierAvailabilityController::class, 'reservations'])->middleware(
            'permission:purchases.read',
        );
        Route::post('supplier-reservations', [SupplierAvailabilityController::class, 'requestReservation'])->middleware(
            'permission:purchases.write',
        );
        Route::post('supplier-reservations/{id}/{action}', [SupplierAvailabilityController::class, 'transition'])
            ->whereUuid('id')
            ->whereIn('action', ['confirm', 'reject', 'request-release', 'confirm-release'])
            ->middleware('permission:purchases.write');

        Route::get('treasury/accounts', [PayablesController::class, 'accounts'])->middleware(
            'permission:payments.request',
        );
        Route::post('treasury/accounts', [PayablesController::class, 'createAccount'])->middleware([
            'permission:finance.write',
            RequireRecentConfirmation::class,
        ]);
        Route::get('payables/obligations', [PayablesController::class, 'obligations'])->middleware(
            'permission:purchases.read',
        );
        Route::get('payables/schedule', [PayablesController::class, 'schedule'])->middleware(
            'permission:purchases.read',
        );
        Route::post('payables/obligations/{id}/reschedule', [PayablesController::class, 'reschedule'])
            ->whereUuid('id')
            ->middleware(['permission:finance.write', RequireRecentConfirmation::class]);
        Route::get('supplier-payments', [PayablesController::class, 'payments'])->middleware(
            'permission:payments.read',
        );
        Route::post('supplier-payments', [PayablesController::class, 'requestPayment'])->middleware(
            'permission:payments.request',
        );
        Route::post('supplier-payments/{id}/verify', [PayablesController::class, 'verifyPayment'])
            ->whereUuid('id')
            ->middleware(['permission:finance.write', RequireRecentConfirmation::class]);
        Route::post('supplier-payments/{id}/allocate', [PayablesController::class, 'allocateAdvance'])
            ->whereUuid('id')
            ->middleware(['permission:finance.write', RequireRecentConfirmation::class]);
        Route::post('supplier-credits', [PayablesController::class, 'credit'])->middleware([
            'permission:finance.write',
            RequireRecentConfirmation::class,
        ]);
        Route::get('supplier-credits', [PayablesController::class, 'credits'])->middleware('permission:purchases.read');
        Route::post('supplier-credits/{id}/allocate', [PayablesController::class, 'allocateCredit'])
            ->whereUuid('id')
            ->middleware(['permission:finance.write', RequireRecentConfirmation::class]);
        Route::get('suppliers/{id}/statement', [PayablesController::class, 'statement'])
            ->whereUuid('id')
            ->middleware('permission:purchases.read');
        Route::get('suppliers/{id}/ledger', [PayablesController::class, 'ledger'])
            ->whereUuid('id')
            ->middleware('permission:purchases.read');
    });
