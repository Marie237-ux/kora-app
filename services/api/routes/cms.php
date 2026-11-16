<?php

use App\Http\Controllers\CmsController;
use App\Http\Middleware\RequireRecentConfirmation;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public: site settings, pages, campaigns and legal conditions in force.
    Route::get('content/revision', [CmsController::class, 'revision'])->middleware('throttle:240,1');
    Route::get('content/contact', [CmsController::class, 'contact'])->middleware('throttle:240,1');
    Route::get('content/pages/{slug}', [CmsController::class, 'showPage'])
        ->where('slug', '[a-z0-9-]{1,120}')
        ->middleware('throttle:240,1');
    Route::get('legal/{kind}', [CmsController::class, 'legal'])->where('kind', '[a-z_]{1,20}');
    Route::get('legal/versions/{id}', [CmsController::class, 'legalVersion'])->whereUuid('id');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('account/legal/pending', [CmsController::class, 'pending']);
        Route::post('account/legal/acceptances', [CmsController::class, 'accept'])->middleware('throttle:30,1');

        Route::prefix('operations/cms')->group(function () {
            Route::get('preview/{token}', [CmsController::class, 'preview'])
                ->where('token', '[A-Za-z0-9]{48}')
                ->middleware('permission:content.read');
            Route::get('documents', [CmsController::class, 'documents'])->middleware('permission:content.read');
            Route::get('documents/{id}/versions', [CmsController::class, 'versions'])
                ->whereUuid('id')
                ->middleware('permission:content.read');
            Route::post('{kind}/{slug}/drafts', [CmsController::class, 'draft'])
                ->where(['kind' => 'settings|page', 'slug' => '[a-z0-9-]{1,120}'])
                ->middleware('permission:content.write');
            Route::post('versions/{id}/publish', [CmsController::class, 'publish'])
                ->whereUuid('id')
                ->middleware('permission:content.publish');
            Route::post('versions/{id}/rollback', [CmsController::class, 'rollback'])
                ->whereUuid('id')
                ->middleware('permission:content.publish');
            Route::get('campaigns', [CmsController::class, 'campaigns'])->middleware('permission:content.read');
            Route::post('campaigns', [CmsController::class, 'saveCampaign'])->middleware('permission:content.write');
            Route::put('campaigns/{id}', [CmsController::class, 'saveCampaign'])
                ->whereUuid('id')
                ->middleware('permission:content.write');
            Route::post('campaigns/{id}/status', [CmsController::class, 'campaignStatus'])
                ->whereUuid('id')
                ->middleware('permission:content.publish');
            Route::get('legal', [CmsController::class, 'legalDrafts'])->middleware('permission:content.read');
            Route::post('legal/{kind}/drafts', [CmsController::class, 'draftLegal'])
                ->where('kind', '[a-z_]{1,20}')
                ->middleware('permission:content.write');
            // Legal texts bind customers: dedicated right and a recent identity confirmation.
            Route::post('legal/{id}/publish', [CmsController::class, 'publishLegal'])
                ->whereUuid('id')
                ->middleware(['permission:legal.publish', RequireRecentConfirmation::class]);
        });
    });
});
