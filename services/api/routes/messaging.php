<?php

use App\Http\Controllers\ConversationsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('account/conversations', [ConversationsController::class, 'mine']);
        Route::post('account/conversations', [ConversationsController::class, 'open'])->middleware('throttle:10,1');
        Route::get('account/conversations/{id}/messages', [ConversationsController::class, 'messages'])->whereUuid(
            'id',
        );
        Route::post('account/conversations/{id}/messages', [ConversationsController::class, 'send'])
            ->whereUuid('id')
            ->middleware('throttle:30,1');
        Route::post('account/conversations/{id}/attachments', [ConversationsController::class, 'attach'])
            ->whereUuid('id')
            ->middleware('throttle:10,1');
        Route::post('account/conversations/{id}/read', [ConversationsController::class, 'read'])->whereUuid('id');
        Route::get('account/conversations/{id}/attachments/{messageId}', [
            ConversationsController::class,
            'attachment',
        ])->whereUuid(['id', 'messageId']);
        Route::get('account/whatsapp-link', [ConversationsController::class, 'whatsapp'])->middleware('throttle:30,1');
    });

    Route::prefix('operations')
        ->middleware('auth:sanctum')
        ->group(function () {
            Route::get('conversations', [ConversationsController::class, 'inbox'])->middleware(
                'permission:support.read',
            );
            Route::get('conversations/{id}/messages', [ConversationsController::class, 'staffMessages'])
                ->whereUuid('id')
                ->middleware('permission:support.read');
            Route::get('conversations/{id}/attachments/{messageId}', [
                ConversationsController::class,
                'staffAttachment',
            ])
                ->whereUuid(['id', 'messageId'])
                ->middleware('permission:support.read');
            Route::post('conversations/{id}/read', [ConversationsController::class, 'staffRead'])
                ->whereUuid('id')
                ->middleware('permission:support.read');
            foreach (
                ['messages' => 'staffSend', 'assign' => 'assign', 'status' => 'status', 'conversion' => 'convert'] as $path => $action
            ) {
                Route::post('conversations/{id}/'.$path, [ConversationsController::class, $action])
                    ->whereUuid('id')
                    ->middleware('permission:support.write');
            }
            Route::get('quick-replies', [ConversationsController::class, 'quickReplies'])->middleware(
                'permission:support.read',
            );
            Route::post('quick-replies', [ConversationsController::class, 'createQuickReply'])->middleware(
                'permission:support.write',
            );
        });
});
