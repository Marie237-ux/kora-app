<?php

use App\Http\Controllers\AccountingController as C;
use App\Http\Controllers\AccountingLetteringController as Lettering;
use App\Http\Controllers\ExpensePlansController as Expenses;
use App\Http\Middleware\RequireRecentConfirmation;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/operations/accounting')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::get('books', [C::class, 'books'])->middleware('permission:accounting.read');
        Route::post('books', [C::class, 'createBook'])->middleware([
            'permission:accounting.settings',
            RequireRecentConfirmation::class,
        ]);
        Route::get('source-types', [C::class, 'sourceTypes'])->middleware('permission:accounting.read');
        Route::prefix('books/{book}')
            ->whereUuid('book')
            ->group(function () {
                Route::get('expenses', [Expenses::class, 'index'])->middleware('permission:accounting.read');
                Route::get('expense-summary', [Expenses::class, 'report'])->middleware('permission:accounting.read');
                Route::get('expenses/{id}', [Expenses::class, 'show'])
                    ->whereUuid('id')
                    ->middleware('permission:accounting.read');
                Route::get('expenses/{id}/payments', [Expenses::class, 'payments'])
                    ->whereUuid('id')
                    ->middleware('permission:accounting.read');
                Route::post('expenses', [Expenses::class, 'create'])->middleware([
                    'permission:accounting.write',
                    RequireRecentConfirmation::class,
                ]);
                Route::post('expenses/{id}/payments', [Expenses::class, 'record'])
                    ->whereUuid('id')
                    ->middleware(['permission:accounting.review', RequireRecentConfirmation::class]);
                Route::post('expenses/{id}/payments/{payment}/reverse', [Expenses::class, 'reverse'])
                    ->whereUuid('id')
                    ->whereUuid('payment')
                    ->middleware(['permission:accounting.reverse', RequireRecentConfirmation::class]);
                foreach (['approve' => 'review', 'cancel' => 'write'] as $action => $permission) {
                    Route::post('expenses/{id}/'.$action, [Expenses::class, 'transition'])
                        ->whereUuid('id')
                        ->defaults('action', $action)
                        ->middleware(['permission:accounting.'.$permission, RequireRecentConfirmation::class]);
                }
                Route::get('letterings', [Lettering::class, 'index'])->middleware('permission:accounting.read');
                Route::get('letterings/{id}', [Lettering::class, 'show'])
                    ->whereUuid('id')
                    ->middleware('permission:accounting.read');
                Route::get('lettering-items', [Lettering::class, 'items'])->middleware('permission:accounting.read');
                Route::post('letterings', [Lettering::class, 'create'])->middleware([
                    'permission:accounting.write',
                    RequireRecentConfirmation::class,
                ]);
                Route::post('letterings/{id}/void', [Lettering::class, 'void'])
                    ->whereUuid('id')
                    ->middleware(['permission:accounting.write', RequireRecentConfirmation::class]);
                Route::get('capture-settings', [C::class, 'captureSettings'])->middleware('permission:accounting.read');
                Route::put('capture-settings', [C::class, 'configureCapture'])->middleware([
                    'permission:accounting.settings',
                    RequireRecentConfirmation::class,
                ]);
                Route::get('capture-events', [C::class, 'captureReceipts'])->middleware('permission:accounting.read');
                Route::get('capture-events/{id}/attempts', [C::class, 'captureAttempts'])
                    ->whereUuid('id')
                    ->middleware('permission:accounting.read');
                Route::post('capture-events/{id}/retry', [C::class, 'retryCapture'])
                    ->whereUuid('id')
                    ->middleware(['permission:accounting.settings', RequireRecentConfirmation::class]);
                Route::get('coverage', [C::class, 'coverage'])->middleware('permission:accounting.read');
                foreach (
                    [
                        '' => 'show',
                        'chart' => 'chart',
                        'chart/revisions' => 'revisions',
                        'journals' => 'journals',
                        'years' => 'years',
                        'entries' => 'entries',
                        'mappings' => 'mappings',
                        'sources' => 'sources',
                    ] as $path => $method
                ) {
                    Route::get($path, [C::class, $method])->middleware('permission:accounting.read');
                }
                Route::get('years/{year}/periods', [C::class, 'periods'])
                    ->whereUuid('year')
                    ->middleware('permission:accounting.read');
                Route::get('entries/{id}', [C::class, 'entry'])
                    ->whereUuid('id')
                    ->middleware('permission:accounting.read');
                Route::get('entries/{id}/revisions', [C::class, 'revisions'])
                    ->whereUuid('id')
                    ->middleware('permission:accounting.read');
                Route::get('reports/{report}', [C::class, 'report'])
                    ->whereIn('report', ['trial-balance', 'ledger'])
                    ->middleware('permission:accounting.read');
                foreach (
                    [
                        ['put', 'chart', 'importChart'],
                        ['post', 'journals', 'saveJournal'],
                        ['put', 'journals/{id}', 'saveJournal'],
                        ['post', 'years', 'createCalendar'],
                        ['post', 'years/{year}/periods', 'createCalendar'],
                        ['post', 'mappings', 'saveMapping'],
                    ] as [$verb, $path, $method]
                ) {
                    Route::$verb($path, [C::class, $method])
                        ->whereUuid('id')
                        ->whereUuid('year')
                        ->middleware(['permission:accounting.settings', RequireRecentConfirmation::class]);
                }
                foreach (
                    ['chart/review' => 'reviewChart', 'mappings/{id}/review' => 'reviewMapping'] as $path => $method
                ) {
                    Route::post($path, [C::class, $method])
                        ->whereUuid('id')
                        ->middleware(['permission:accounting.review', RequireRecentConfirmation::class]);
                }
                foreach (
                    [
                        ['post', 'entries', 'saveEntry'],
                        ['put', 'entries/{id}', 'saveEntry'],
                        ['post', 'entries/{id}/cancel', 'cancel'],
                        ['post', 'sources', 'capture'],
                        ['post', 'sources/{id}/prepare', 'prepare'],
                    ] as [$verb, $path, $method]
                ) {
                    Route::$verb($path, [C::class, $method])
                        ->whereUuid('id')
                        ->middleware('permission:accounting.write');
                }
                foreach (['post', 'reverse'] as $action) {
                    Route::post('entries/{id}/'.$action, [C::class, $action])
                        ->whereUuid('id')
                        ->middleware(['permission:accounting.'.$action, RequireRecentConfirmation::class]);
                }
                foreach (['close', 'reopen'] as $action) {
                    Route::post('{kind}/{id}/'.$action, [C::class, 'transition'])
                        ->whereIn('kind', ['years', 'periods'])
                        ->whereUuid('id')
                        ->defaults('action', $action)
                        ->middleware(['permission:accounting.'.$action, RequireRecentConfirmation::class]);
                }
            });
    });
