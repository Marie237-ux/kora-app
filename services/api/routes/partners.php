<?php

use App\Http\Controllers\PartnersController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public: the partner page and the application form (no account needed, rate limited).
    Route::get('partners', [PartnersController::class, 'published'])->middleware('throttle:120,1');
    Route::post('partner-applications', [PartnersController::class, 'apply'])->middleware('throttle:5,1');

    Route::prefix('operations')
        ->middleware('auth:sanctum')
        ->group(function () {
            Route::get('partner-applications', [PartnersController::class, 'applications'])->middleware(
                'permission:partners.read',
            );
            Route::get('partner-applications/{id}', [PartnersController::class, 'application'])
                ->whereUuid('id')
                ->middleware('permission:partners.read');
            foreach (['notes' => 'note', 'decision' => 'decide', 'conversion' => 'convert'] as $path => $action) {
                Route::post('partner-applications/{id}/'.$path, [PartnersController::class, $action])
                    ->whereUuid('id')
                    ->middleware('permission:partners.manage');
            }
            Route::post('partner-applications/{id}/attachments', [PartnersController::class, 'attach'])
                ->whereUuid('id')
                ->middleware(['permission:partners.manage', 'throttle:20,1']);
            Route::get('partners', [PartnersController::class, 'partners'])->middleware('permission:partners.read');
            Route::post('partners', [PartnersController::class, 'save'])->middleware('permission:partners.manage');
            Route::put('partners/{id}', [PartnersController::class, 'save'])
                ->whereUuid('id')
                ->middleware('permission:partners.manage');
            Route::post('partners/{id}/status', [PartnersController::class, 'status'])
                ->whereUuid('id')
                ->middleware('permission:partners.manage');
        });
});
