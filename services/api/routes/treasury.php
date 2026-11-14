<?php

use App\Http\Controllers\BalanceObservationsController as B;
use App\Http\Controllers\FinancingsController as F;
use App\Http\Controllers\TreasuryCoverageController as Coverage;
use App\Http\Controllers\TreasuryForecastController as Forecast;
use App\Http\Controllers\TreasuryReconciliationController as C;
use App\Http\Controllers\WithdrawalsController as W;
use App\Http\Middleware\RequireRecentConfirmation;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/operations/accounting/books/{book}/treasury')
    ->whereUuid('book')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::get('financing-summary', [F::class, 'summary'])->middleware('permission:accounting.read');
        Route::get('financings', [F::class, 'index'])->middleware('permission:accounting.read');
        Route::post('financings', [F::class, 'create'])->middleware([
            'permission:accounting.write',
            RequireRecentConfirmation::class,
        ]);
        Route::get('financings/{id}', [F::class, 'show'])
            ->whereUuid('id')
            ->middleware('permission:accounting.read');
        foreach (['approve', 'cancel'] as $action) {
            Route::post('financings/{id}/'.$action, [F::class, 'transition'])
                ->whereUuid('id')
                ->defaults('action', $action)
                ->middleware(['permission:accounting.review', RequireRecentConfirmation::class]);
        }
        Route::get('financings/{id}/movements', [F::class, 'movements'])
            ->whereUuid('id')
            ->middleware('permission:accounting.read');
        Route::post('financings/{id}/movements', [F::class, 'record'])
            ->whereUuid('id')
            ->middleware(['permission:accounting.review', RequireRecentConfirmation::class]);
        Route::post('financings/{id}/movements/{movement}/reverse', [F::class, 'reverse'])
            ->whereUuid('id')
            ->whereUuid('movement')
            ->middleware(['permission:accounting.reverse', RequireRecentConfirmation::class]);
        Route::get('balance-observation-summary', [B::class, 'summary'])->middleware('permission:accounting.read');
        Route::get('balance-observations', [B::class, 'index'])->middleware('permission:accounting.read');
        Route::post('balance-observations', [B::class, 'create'])->middleware([
            'permission:accounting.write',
            RequireRecentConfirmation::class,
        ]);
        Route::get('balance-observations/{id}', [B::class, 'show'])
            ->whereUuid('id')
            ->middleware('permission:accounting.read');
        foreach (['approve', 'void'] as $action) {
            Route::post('balance-observations/{id}/'.$action, [B::class, 'transition'])
                ->whereUuid('id')
                ->defaults('action', $action)
                ->middleware(['permission:accounting.review', RequireRecentConfirmation::class]);
        }
        Route::get('withdrawal-summary', [W::class, 'summary'])->middleware('permission:accounting.read');
        Route::get('withdrawals', [W::class, 'index'])->middleware('permission:accounting.read');
        Route::post('withdrawals', [W::class, 'create'])->middleware([
            'permission:accounting.write',
            RequireRecentConfirmation::class,
        ]);
        Route::get('withdrawals/{id}', [W::class, 'show'])
            ->whereUuid('id')
            ->middleware('permission:accounting.read');
        Route::get('withdrawals/{id}/receipts', [W::class, 'receipts'])
            ->whereUuid('id')
            ->middleware('permission:accounting.read');
        Route::post('withdrawals/{id}/receipts', [W::class, 'record'])
            ->whereUuid('id')
            ->middleware(['permission:accounting.review', RequireRecentConfirmation::class]);
        foreach (['approve', 'cancel', 'void'] as $action) {
            Route::post('withdrawals/{id}/'.$action, [W::class, 'transition'])
                ->whereUuid('id')
                ->defaults('action', $action)
                ->middleware(['permission:accounting.review', RequireRecentConfirmation::class]);
        }
        Route::post('withdrawals/{id}/receipts/{receipt}/reverse', [W::class, 'reverse'])
            ->whereUuid('id')
            ->whereUuid('receipt')
            ->middleware(['permission:accounting.reverse', RequireRecentConfirmation::class]);
        Route::get('forecast', [Forecast::class, 'show'])->middleware('permission:accounting.read');
        Route::get('coverage-policies', [Coverage::class, 'policies'])->middleware('permission:accounting.read');
        Route::get('coverage', [Coverage::class, 'report'])->middleware('permission:accounting.read');
        Route::post('coverage-policies', [Coverage::class, 'create'])->middleware([
            'permission:accounting.settings',
            RequireRecentConfirmation::class,
        ]);
        foreach (['review' => 'review', 'void' => 'settings'] as $action => $permission) {
            Route::post('coverage-policies/{id}/'.$action, [Coverage::class, 'transition'])
                ->whereUuid('id')
                ->defaults('action', $action)
                ->middleware(['permission:accounting.'.$permission, RequireRecentConfirmation::class]);
        }
        Route::get('bindings', [C::class, 'bindings'])->middleware('permission:accounting.read');
        Route::post('bindings', [C::class, 'createBinding'])->middleware([
            'permission:accounting.settings',
            RequireRecentConfirmation::class,
        ]);
        Route::post('bindings/{id}/review', [C::class, 'reviewBinding'])
            ->whereUuid('id')
            ->middleware(['permission:accounting.review', RequireRecentConfirmation::class]);
        Route::get('statements', [C::class, 'statements'])->middleware('permission:accounting.read');
        Route::post('statements', [C::class, 'import'])->middleware([
            'permission:accounting.write',
            RequireRecentConfirmation::class,
        ]);
        Route::prefix('statements/{id}')
            ->whereUuid('id')
            ->group(function () {
                foreach (
                    [
                        '' => 'show',
                        'rows' => 'rows',
                        'ledger' => 'ledger',
                        'matches' => 'matches',
                        'outstanding' => 'outstanding',
                        'closures' => 'closures',
                    ] as $path => $method
                ) {
                    Route::get($path, [C::class, $method])->middleware('permission:accounting.read');
                }
                Route::post('matches', [C::class, 'match'])->middleware([
                    'permission:accounting.write',
                    RequireRecentConfirmation::class,
                ]);
                Route::post('outstanding', [C::class, 'createOutstanding'])->middleware([
                    'permission:accounting.write',
                    RequireRecentConfirmation::class,
                ]);
                foreach (['review' => 'review', 'void' => 'write'] as $action => $permission) {
                    Route::post('outstanding/{item}/'.$action, [C::class, 'transitionOutstanding'])
                        ->whereUuid('item')
                        ->defaults('action', $action)
                        ->middleware(['permission:accounting.'.$permission, RequireRecentConfirmation::class]);
                }
                Route::post('matches/{match}/void', [C::class, 'unmatch'])
                    ->whereUuid('match')
                    ->middleware(['permission:accounting.write', RequireRecentConfirmation::class]);
                foreach (['close' => 'close', 'reopen' => 'reopen', 'void' => 'write'] as $action => $permission) {
                    Route::post($action, [C::class, 'transition'])
                        ->defaults('action', $action)
                        ->middleware(['permission:accounting.'.$permission, RequireRecentConfirmation::class]);
                }
            });
    });
