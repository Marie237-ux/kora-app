<?php

use App\Domain\Accounting\AccountingExports;
use App\Http\Controllers\AccountingStatementsController as C;
use App\Http\Middleware\RequireRecentConfirmation;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/operations')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::prefix('accounting/books/{book}')
            ->whereUuid('book')
            ->group(function () {
                Route::get('statement-layouts', [C::class, 'layouts'])->middleware('permission:accounting.read');
                Route::post('statement-layouts/{kind}', [C::class, 'saveLayout'])
                    ->whereIn('kind', ['income_statement', 'balance_sheet'])
                    ->middleware(['permission:accounting.settings', RequireRecentConfirmation::class]);
                Route::post('statement-layouts/{id}/review', [C::class, 'reviewLayout'])
                    ->whereUuid('id')
                    ->middleware(['permission:accounting.review', RequireRecentConfirmation::class]);
                Route::get('statements/{kind}', [C::class, 'statement'])
                    ->whereIn('kind', ['income_statement', 'balance_sheet'])
                    ->middleware('permission:accounting.read');
                Route::get('exports', [C::class, 'exports'])->middleware('permission:accounting.read');
                Route::get('exports/{report}', [C::class, 'export'])
                    ->whereIn('report', AccountingExports::REPORTS)
                    ->middleware(['permission:accounting.read', RequireRecentConfirmation::class, 'throttle:20,1']);
            });
        // Management figure, not the accounting result: owner and finance roles.
        Route::get('finance/margins', [C::class, 'margins'])->middleware('permission:finance.read');
    });
