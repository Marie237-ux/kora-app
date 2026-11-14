<?php

use App\Http\Controllers\SmartSearchController as C;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public, guest or signed-in: the photo belongs to its session or account only.
    Route::post('search/photo', [C::class, 'photo'])->middleware('throttle:10,1');
    Route::get('search/photo/{id}', [C::class, 'photoResults'])
        ->whereUuid('id')
        ->middleware('throttle:60,1');
    Route::get('search/photo/{id}/image', [C::class, 'photoImage'])
        ->whereUuid('id')
        ->middleware('throttle:60,1');
    Route::post('search/photo/{id}/product-request', [C::class, 'photoRequest'])
        ->whereUuid('id')
        ->middleware('throttle:10,1');
    Route::get('assistant/choice/{slug}', [C::class, 'questions'])
        ->where('slug', '[a-z0-9-]{1,120}')
        ->middleware('throttle:120,1');
    Route::post('assistant/choice', [C::class, 'recommend'])->middleware('throttle:60,1');
    Route::get('compare', [C::class, 'compare'])->middleware('throttle:120,1');

    Route::prefix('operations')
        ->middleware('auth:sanctum')
        ->group(function () {
            Route::post('catalogue/products/{id}/assistant', [C::class, 'assist'])
                ->whereUuid('id')
                ->middleware(['permission:catalogue.write', 'throttle:20,1']);
            Route::get('catalogue/products/{id}/assistant', [C::class, 'suggestions'])
                ->whereUuid('id')
                ->middleware('permission:catalogue.read');
            Route::post('ai-suggestions/{id}/apply', [C::class, 'apply'])
                ->whereUuid('id')
                ->middleware('permission:catalogue.write');
            Route::post('ai-suggestions/{id}/discard', [C::class, 'discard'])
                ->whereUuid('id')
                ->middleware('permission:catalogue.write');
            Route::get('ai/usage', [C::class, 'usage'])->middleware('permission:system.read');
            Route::get('search/photo-index', [C::class, 'indexStatus'])->middleware('permission:catalogue.read');
            Route::post('search/photo-index/reindex', [C::class, 'reindex'])->middleware(
                'permission:catalogue.publish',
            );
        });
});
