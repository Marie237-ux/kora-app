<?php

use App\Http\Controllers\SupplierPortalController as C;
use App\Http\Middleware\RequireRecentConfirmation;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware('auth:sanctum')
    ->group(function () {
        // Supplier members: every route checks the active membership of this supplier and that the portal is on.
        Route::get('supplier-portal', [C::class, 'memberships']);
        Route::post('supplier-invitations/{token}/accept', [C::class, 'accept'])
            ->where('token', '[a-f0-9]{64}')
            ->middleware('throttle:10,1');
        Route::prefix('supplier-portal/{supplier}')
            ->whereUuid('supplier')
            ->middleware('throttle:120,1')
            ->group(function () {
                Route::get('/', [C::class, 'overview']);
                Route::get('offers', [C::class, 'offers']);
                Route::post('offers/{offer}/availability', [C::class, 'declare'])->whereUuid('offer');
                Route::get('purchases', [C::class, 'purchases']);
                Route::post('purchases/{id}/response', [C::class, 'respondPurchase'])->whereUuid('id');
                Route::get('collections', [C::class, 'collections']);
                Route::post('collections/{id}/response', [C::class, 'respondCollection'])->whereUuid('id');
                Route::get('warranties', [C::class, 'warranties']);
                Route::get('statement', [C::class, 'statement']);
                Route::get('contracts', [C::class, 'contracts']);
                Route::get('documents', [C::class, 'documents']);
                Route::post('documents', [C::class, 'upload'])->middleware('throttle:20,1');
                Route::get('documents/{document}', [C::class, 'download'])->whereUuid('document');
                Route::get('proposals', [C::class, 'proposals']);
                Route::post('proposals', [C::class, 'propose'])->middleware('throttle:30,1');
                Route::post('proposals/{id}/withdraw', [C::class, 'withdraw'])->whereUuid('id');
            });

        Route::prefix('operations')->group(function () {
            Route::get('supplier-portal/settings', [C::class, 'settings'])->middleware('permission:suppliers.portal');
            // Opening the portal to suppliers is an owner decision with a recent identity confirmation.
            Route::put('supplier-portal/settings', [C::class, 'saveSettings'])->middleware([
                'permission:suppliers.portal_settings',
                RequireRecentConfirmation::class,
            ]);
            Route::get('suppliers/{id}/portal-access', [C::class, 'access'])
                ->whereUuid('id')
                ->middleware('permission:suppliers.portal');
            Route::post('suppliers/{id}/portal-invitations', [C::class, 'invite'])
                ->whereUuid('id')
                ->middleware('permission:suppliers.portal');
            Route::post('supplier-invitations/{id}/revoke', [C::class, 'revokeInvitation'])
                ->whereUuid('id')
                ->middleware('permission:suppliers.portal');
            Route::post('supplier-members/{id}/revoke', [C::class, 'revokeMember'])
                ->whereUuid('id')
                ->middleware('permission:suppliers.portal');
            Route::get('supplier-proposals', [C::class, 'staffProposals'])->middleware('permission:suppliers.portal');
            Route::post('supplier-proposals/{id}/review', [C::class, 'review'])
                ->whereUuid('id')
                ->middleware('permission:suppliers.portal');
            Route::get('supplier-availability-declarations', [C::class, 'declarations'])->middleware(
                'permission:suppliers.portal',
            );
            Route::post('supplier-availability-declarations/{id}/review', [C::class, 'reviewDeclaration'])
                ->whereUuid('id')
                ->middleware(['permission:suppliers.portal', 'permission:purchases.write']);
            Route::post('purchase-orders/{id}/share', [C::class, 'sharePurchase'])
                ->whereUuid('id')
                ->middleware(['permission:suppliers.portal', 'permission:purchases.write']);
            Route::get('suppliers/{id}/portal-responses', [C::class, 'responses'])
                ->whereUuid('id')
                ->middleware('permission:suppliers.portal');
            Route::get('suppliers/{id}/shared-documents', [C::class, 'sharedDocuments'])
                ->whereUuid('id')
                ->middleware('permission:suppliers.portal');
            Route::post('suppliers/{id}/shared-documents', [C::class, 'share'])
                ->whereUuid('id')
                ->middleware('permission:suppliers.portal');
            Route::post('suppliers/{id}/shared-documents/{share}/revoke', [C::class, 'unshare'])
                ->whereUuid(['id', 'share'])
                ->middleware('permission:suppliers.portal');
        });
    });
