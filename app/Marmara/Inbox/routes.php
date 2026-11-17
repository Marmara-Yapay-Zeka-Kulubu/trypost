<?php

declare(strict_types=1);

use App\Http\Middleware\App\EnsureAccountReady;
use App\Http\Middleware\App\EnsureHasWorkspace;
use App\Marmara\Inbox\Http\EnsureInboxEnabled;
use App\Marmara\Inbox\Http\InboxApiController;
use App\Marmara\Inbox\Http\InboxPageController;
use App\Marmara\Inbox\Http\ZernioInboxWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Marmara AI fork: inbox routes (app/Marmara/README.md). Loaded by
| InboxServiceProvider, always registered (route:cache keeps them) and 404
| while MARMARA_INBOX_ENABLED is off.
*/

Route::middleware(['web', 'auth', EnsureAccountReady::class, EnsureHasWorkspace::class, EnsureInboxEnabled::class])
    ->prefix('inbox')
    ->name('marmara.inbox.')
    ->group(function (): void {
        Route::get('/', [InboxPageController::class, 'index'])->name('index');
        Route::post('{item}/replies', [InboxPageController::class, 'reply'])->middleware('throttle:30,1')->name('replies.store');
        Route::post('{item}/read', [InboxPageController::class, 'read'])->name('read');
        Route::post('{item}/unread', [InboxPageController::class, 'unread'])->name('unread');
        Route::post('{item}/archive', [InboxPageController::class, 'archive'])->name('archive');
        Route::post('{item}/unarchive', [InboxPageController::class, 'unarchive'])->name('unarchive');
        Route::post('{item}/hide', [InboxPageController::class, 'hide'])->middleware('throttle:30,1')->name('hide');
    });

Route::middleware(['api', 'auth:api', 'workspace.token', 'throttle:api', EnsureInboxEnabled::class])
    ->prefix('api/inbox')
    ->name('api.inbox.')
    ->group(function (): void {
        Route::get('items', [InboxApiController::class, 'index'])->name('items.index');
        Route::get('items/{item}', [InboxApiController::class, 'show'])->name('items.show');
        Route::post('items/{item}/replies', [InboxApiController::class, 'reply'])->middleware('throttle:30,1')->name('items.replies.store');
        Route::post('items/{item}/read', [InboxApiController::class, 'read'])->name('items.read');
    });

// Optional Zernio inbox webhook receiver (MARMARA_INBOX_ZERNIO_WEBHOOK_SECRET).
Route::middleware(['api', 'throttle:120,1', EnsureInboxEnabled::class])
    ->post('api/marmara/zernio/inbox-webhook', ZernioInboxWebhookController::class)
    ->name('marmara.inbox.zernio-webhook');
