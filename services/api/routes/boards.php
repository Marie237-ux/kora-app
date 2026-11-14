<?php

use App\Http\Controllers\BoardMembersController;
use App\Http\Controllers\BoardsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('boards/public', [BoardsController::class, 'publicIndex'])->middleware('throttle:60,1');
    Route::get('boards/public/{id}', [BoardsController::class, 'publicShow'])
        ->whereUuid('id')
        ->middleware('throttle:60,1');
    Route::get('boards/shared/{id}/{token}', [BoardsController::class, 'shared'])
        ->whereUuid('id')
        ->where('token', '[a-f0-9]{64}')
        ->middleware('throttle:60,1');
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('boards', [BoardsController::class, 'index']);
        Route::post('boards', [BoardsController::class, 'create'])->middleware('throttle:20,1');
        Route::get('board-invitations/{token}', [BoardMembersController::class, 'preview'])
            ->where('token', '[a-f0-9]{64}')
            ->middleware('throttle:30,1');
        Route::post('board-invitations/{token}/accept', [BoardMembersController::class, 'accept'])
            ->where('token', '[a-f0-9]{64}')
            ->middleware('throttle:15,1');
        Route::prefix('boards/{id}')
            ->whereUuid('id')
            ->group(function () {
                Route::get('/', [BoardsController::class, 'show']);
                Route::put('/', [BoardsController::class, 'update'])->middleware('throttle:60,1');
                Route::post('gift-settings', [BoardsController::class, 'giftSettings'])->middleware('throttle:30,1');
                Route::post('archive', [BoardsController::class, 'archive'])->middleware('throttle:15,1');
                Route::post('visibility', [BoardsController::class, 'visibility'])->middleware('throttle:15,1');
                Route::post('share', [BoardsController::class, 'share'])->middleware('throttle:15,1');
                Route::post('sections', [BoardsController::class, 'addSection'])->middleware('throttle:30,1');
                Route::put('sections/{sectionId}', [BoardsController::class, 'renameSection'])
                    ->whereUuid('sectionId')
                    ->middleware('throttle:60,1');
                Route::delete('sections/{sectionId}', [BoardsController::class, 'removeSection'])
                    ->whereUuid('sectionId')
                    ->middleware('throttle:30,1');
                Route::post('sections/reorder', [BoardsController::class, 'reorderSections'])->middleware(
                    'throttle:60,1',
                );
                Route::post('pins', [BoardsController::class, 'addPin'])->middleware('throttle:60,1');
                Route::put('pins/{pinId}', [BoardsController::class, 'editPin'])
                    ->whereUuid('pinId')
                    ->middleware('throttle:60,1');
                Route::delete('pins/{pinId}', [BoardsController::class, 'removePin'])
                    ->whereUuid('pinId')
                    ->middleware('throttle:60,1');
                Route::post('pins/reorder', [BoardsController::class, 'reorderPins'])->middleware('throttle:60,1');
                Route::post('pins/{pinId}/transfer', [BoardsController::class, 'transfer'])
                    ->whereUuid('pinId')
                    ->middleware('throttle:30,1');
                Route::post('to-cart', [BoardsController::class, 'toCart'])->middleware('throttle:30,1');
                Route::post('from-cart', [BoardsController::class, 'fromCart'])->middleware('throttle:30,1');
                Route::get('events', [BoardsController::class, 'events']);
                Route::get('members', [BoardMembersController::class, 'members']);
                Route::put('members/{userId}', [BoardMembersController::class, 'member'])
                    ->whereNumber('userId')
                    ->middleware('throttle:30,1');
                Route::get('invitations', [BoardMembersController::class, 'invitations']);
                Route::post('invitations', [BoardMembersController::class, 'invite'])->middleware('throttle:15,1');
                Route::post('invitations/{invitationId}/revoke', [BoardMembersController::class, 'revokeInvite'])
                    ->whereUuid('invitationId')
                    ->middleware('throttle:30,1');
            });
    });
});
