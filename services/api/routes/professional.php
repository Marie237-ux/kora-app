<?php

use App\Http\Controllers\ProfessionalController;
use App\Http\Middleware\RequireCompanyMembership;
use App\Http\Middleware\RequireRecentConfirmation;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/professional')
    ->middleware(['auth:sanctum', 'throttle:60,1', RequireCompanyMembership::class])
    ->group(function () {
        Route::get('profiles', [ProfessionalController::class, 'profiles']);
        Route::post('profiles', [ProfessionalController::class, 'createProfile']);
        Route::get('profiles/{id}', [ProfessionalController::class, 'showProfile'])->whereUuid('id');
        Route::put('profiles/{id}', [ProfessionalController::class, 'updateProfile'])->whereUuid('id');
        Route::post('profiles/{id}/members', [ProfessionalController::class, 'saveMember'])->whereUuid('id');
        Route::delete('profiles/{id}/members/{userId}', [ProfessionalController::class, 'removeMember'])
            ->whereUuid('id')
            ->whereNumber('userId');
        Route::post('profiles/{id}/addresses', [ProfessionalController::class, 'saveAddress'])->whereUuid('id');
        Route::put('profiles/{id}/addresses/{addressId}', [ProfessionalController::class, 'saveAddress'])->whereUuid([
            'id',
            'addressId',
        ]);
        Route::delete('profiles/{id}/addresses/{addressId}', [
            ProfessionalController::class,
            'archiveAddress',
        ])->whereUuid(['id', 'addressId']);
        Route::get('profiles/{id}/quotes', [ProfessionalController::class, 'quotes'])->whereUuid('id');
        Route::post('profiles/{id}/quotes', [ProfessionalController::class, 'requestQuote'])->whereUuid('id');
        Route::get('profiles/{id}/quotes/{quoteId}', [ProfessionalController::class, 'showQuote'])->whereUuid([
            'id',
            'quoteId',
        ]);
        Route::post('profiles/{id}/quotes/{quoteId}/accept', [ProfessionalController::class, 'accept'])->whereUuid([
            'id',
            'quoteId',
        ]);
        Route::post('profiles/{id}/quotes/{quoteId}/decline', [ProfessionalController::class, 'decline'])->whereUuid([
            'id',
            'quoteId',
        ]);
        Route::post('profiles/{id}/quotes/{quoteId}/checkout-preview', [
            ProfessionalController::class,
            'preview',
        ])->whereUuid(['id', 'quoteId']);
        Route::post('profiles/{id}/quotes/{quoteId}/convert', [ProfessionalController::class, 'convert'])->whereUuid([
            'id',
            'quoteId',
        ]);
        Route::get('profiles/{id}/orders/{orderId}', [ProfessionalController::class, 'sharedOrder'])->whereUuid([
            'id',
            'orderId',
        ]);
    });
Route::prefix('v1/operations/professional-quotes')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::get('', [ProfessionalController::class, 'manageIndex'])->middleware('permission:professional.read');
        Route::get('{quoteId}', [ProfessionalController::class, 'manageShow'])
            ->whereUuid('quoteId')
            ->middleware('permission:professional.read');
        Route::post('{quoteId}/issue', [ProfessionalController::class, 'issue'])
            ->whereUuid('quoteId')
            ->middleware(['permission:professional.manage', RequireRecentConfirmation::class]);
    });
