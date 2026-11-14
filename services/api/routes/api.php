<?php

use App\Domain\Catalogue\CatalogueManager;
use App\Http\Controllers\AgreementController;
use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\HeroController;
use App\Http\Controllers\ManageCatalogueController;
use App\Http\Controllers\MediaController;
use App\Http\Middleware\CatalogueAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require __DIR__.'/operations.php';
require __DIR__.'/customer.php';
require __DIR__.'/boards.php';
require __DIR__.'/delivery.php';
require __DIR__.'/orders.php';
require __DIR__.'/documents.php';
require __DIR__.'/promotions.php';
require __DIR__.'/professional.php';
require __DIR__.'/loyalty.php';
require __DIR__.'/accounting.php';
require __DIR__.'/treasury.php';
require __DIR__.'/missions.php';
require __DIR__.'/after_sales.php';
require __DIR__.'/referrals.php';
require __DIR__.'/partners.php';
require __DIR__.'/engagement.php';
require __DIR__.'/messaging.php';
require __DIR__.'/cms.php';
require __DIR__.'/smart_search.php';
require __DIR__.'/supplier_portal.php';
require __DIR__.'/accounting_statements.php';
require __DIR__.'/supplier_offers.php';

Route::prefix('v1')->group(function () {
    Route::get('health', fn () => ['data' => ['status' => 'ok']]);
    Route::get('catalogue', [CatalogueController::class, 'index'])->middleware('throttle:120,1');
    Route::get('catalogue/facets', [CatalogueController::class, 'facets'])->middleware('throttle:120,1');
    Route::get('catalogue/suggestions', [CatalogueController::class, 'suggestions'])->middleware('throttle:60,1');
    Route::get('catalogue/{id}', [CatalogueController::class, 'show'])->whereUuid('id');
    Route::get('shops', [CatalogueController::class, 'shops']);
    Route::get('categories', [CatalogueController::class, 'categories']);
    Route::get('shops/{slug}', [CatalogueController::class, 'shop']);
    Route::get('catalogue/resolve/{entity}/{slug}', [CatalogueController::class, 'resolve'])->whereIn('entity', [
        'shops',
        'categories',
        'products',
    ]);
    Route::get('media/{id}/public', [MediaController::class, 'publicFile'])->whereUuid('id');
    Route::get('content/hero', [HeroController::class, 'published']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('operations/catalogue/lookups', [ManageCatalogueController::class, 'lookups'])->middleware(
            'permission:catalogue.read',
        );
        Route::prefix('operations/catalogue/{entity}')
            ->whereIn('entity', CatalogueManager::ENTITIES)
            ->middleware(CatalogueAccess::class)
            ->group(function () {
                Route::get('/', [ManageCatalogueController::class, 'index']);
                Route::post('/', [ManageCatalogueController::class, 'store']);
                Route::get('{id}', [ManageCatalogueController::class, 'show'])->whereUuid('id');
                Route::put('{id}', [ManageCatalogueController::class, 'update'])->whereUuid('id');
                Route::post('{id}/publish', [ManageCatalogueController::class, 'publish'])
                    ->whereUuid('id')
                    ->name('catalogue.publish');
                Route::post('{id}/archive', [ManageCatalogueController::class, 'archive'])
                    ->whereUuid('id')
                    ->name('catalogue.archive');
                Route::get('{id}/revisions', [ManageCatalogueController::class, 'revisions'])->whereUuid('id');
            });
        Route::get('operations/pricing/agreements', [AgreementController::class, 'index'])->middleware(
            'permission:pricing.read',
        );
        Route::post('operations/pricing/agreements', [AgreementController::class, 'store'])->middleware(
            'permission:pricing.write',
        );
        Route::post('operations/pricing/agreements/{id}/withdraw', [AgreementController::class, 'withdraw'])
            ->whereUuid('id')
            ->middleware('permission:pricing.write');
        Route::get('operations/pricing/offers/{id}/preview', [ManageCatalogueController::class, 'pricePreview'])
            ->whereUuid('id')
            ->middleware('permission:pricing.read');
        Route::get('operations/media', [MediaController::class, 'index'])->middleware('permission:media.read');
        Route::post('operations/media', [MediaController::class, 'store'])->middleware([
            'permission:media.write',
            'throttle:20,1',
        ]);
        Route::get('operations/media/{id}/file', [MediaController::class, 'privateFile'])
            ->whereUuid('id')
            ->middleware('permission:media.read');
        Route::post('operations/media/{id}/visibility', [MediaController::class, 'visibility'])
            ->whereUuid('id')
            ->middleware('permission:media.publish');
        Route::get(
            'me',
            fn (Request $request) => [
                'data' => [
                    ...$request->user()->publicProfile(),
                    'mfa_verified' => $request->hasSession() && (bool) $request->session()->get('mfa_verified'),
                ],
            ],
        );
        Route::patch('me/preferences', function (Request $request) {
            $data = $request->validate([
                'locale' => ['required', 'in:fr,en'],
                'theme' => ['required', 'in:light,dark,system'],
            ]);
            $request->user()->forceFill($data)->save();

            return ['data' => $request->user()->publicProfile()];
        });
        Route::prefix('operations/content')->group(function () {
            Route::get('scenes', [HeroController::class, 'index'])->middleware('permission:content.read');
            Route::get('scenes/{id}/preview', [HeroController::class, 'preview'])
                ->whereUuid('id')
                ->middleware('permission:content.read');
            Route::get('scenes/{id}/revisions', [HeroController::class, 'revisions'])
                ->whereUuid('id')
                ->middleware('permission:content.read');
            Route::post('scenes', [HeroController::class, 'store'])->middleware('permission:content.write');
            Route::put('scenes/{id}', [HeroController::class, 'update'])
                ->whereUuid('id')
                ->middleware('permission:content.write');
            Route::post('reorder', [HeroController::class, 'reorder'])->middleware('permission:content.write');
            Route::post('scenes/{id}/restore', [HeroController::class, 'restore'])
                ->whereUuid('id')
                ->middleware('permission:content.write');
            Route::post('scenes/{id}/publish', [HeroController::class, 'publish'])
                ->whereUuid('id')
                ->middleware('permission:content.publish');
            Route::post('scenes/{id}/pause', [HeroController::class, 'pause'])
                ->whereUuid('id')
                ->middleware('permission:content.publish');
        });
    });
});
