<?php

declare(strict_types=1);

use App\Http\Middleware\App\EnsureAccountReady;
use App\Http\Middleware\App\EnsureHasWorkspace;
use App\Marmara\Designs\Http\DesignController;
use App\Marmara\Designs\Http\DesignMediaController;
use App\Marmara\Designs\Http\DesignPostController;
use App\Marmara\Designs\Http\DesignsApiController;
use App\Marmara\Designs\Http\DesignsPageController;
use App\Marmara\Designs\Http\DesignUploadController;
use App\Marmara\Designs\Http\DriveConnectController;
use App\Marmara\Designs\Http\EnsureDesignsEnabled;
use App\Marmara\Designs\Http\PrivacyPolicyController;
use Illuminate\Support\Facades\Route;

/*
| Marmara AI fork: designs gallery routes (app/Marmara/README.md, Designs).
| Loaded by DesignsServiceProvider, always registered (route:cache keeps
| them) and 404 while MARMARA_DESIGNS_ENABLED is off.
*/

Route::middleware(['web', 'auth', EnsureAccountReady::class, EnsureHasWorkspace::class, EnsureDesignsEnabled::class])
    ->prefix('designs')
    ->name('marmara.designs.')
    ->group(function (): void {
        Route::get('/', [DesignsPageController::class, 'index'])->name('index');
        Route::post('upload', [DesignUploadController::class, 'store'])->middleware('throttle:20,1')->name('upload');

        Route::get('drive/connect', [DriveConnectController::class, 'connect'])->name('drive.connect');
        Route::get('drive/callback', [DriveConnectController::class, 'callback'])->name('drive.callback');
        Route::get('drive/picker-token', [DriveConnectController::class, 'pickerToken'])->middleware('throttle:20,1')->name('drive.picker-token');
        Route::post('drive/folder', [DriveConnectController::class, 'grantFolder'])->name('drive.folder');
        Route::delete('drive', [DriveConnectController::class, 'disconnect'])->name('drive.disconnect');

        Route::get('{design}/thumbnail/{size}', [DesignMediaController::class, 'thumbnail'])->whereNumber('size')->middleware('throttle:600,1')->name('thumbnail');
        Route::get('{design}/original', [DesignMediaController::class, 'original'])->middleware('throttle:30,1')->name('original');
        // A <video> asks for several byte ranges while it plays and seeks.
        Route::get('{design}/play', [DesignMediaController::class, 'play'])->middleware('throttle:240,1')->name('play');
        Route::patch('{design}/status', [DesignController::class, 'status'])->name('status');
        Route::put('{design}/tags', [DesignController::class, 'tags'])->name('tags');
        Route::post('{design}/comments', [DesignController::class, 'storeComment'])->middleware('throttle:30,1')->name('comments.store');
        Route::patch('{design}/comments/{comment}', [DesignController::class, 'updateComment'])->name('comments.update');
        Route::delete('{design}/comments/{comment}', [DesignController::class, 'destroyComment'])->name('comments.destroy');
        Route::post('{design}/vote', [DesignController::class, 'vote'])->name('vote');
        Route::delete('{design}/vote', [DesignController::class, 'unvote'])->name('unvote');
        Route::post('{design}/versions', [DesignController::class, 'linkVersion'])->name('versions.link');
        Route::delete('{design}/versions', [DesignController::class, 'unlinkVersion'])->name('versions.unlink');
        Route::post('{design}/posts', [DesignPostController::class, 'store'])->middleware('throttle:10,1')->name('posts.store');
    });

// Thumbnails for readers without a session (webhook payloads, API, MCP): temporary signed URLs.
Route::middleware(['signed', 'throttle:300,1', EnsureDesignsEnabled::class])
    ->get('marmara/designs/{design}/thumbnail/{size}', [DesignMediaController::class, 'signedThumbnail'])
    ->whereNumber('size')
    ->name('marmara.designs.thumbnail.signed');

Route::middleware(['api', 'auth:api', 'workspace.token', 'throttle:api', EnsureDesignsEnabled::class])
    ->prefix('api/designs')
    ->name('api.designs.')
    ->group(function (): void {
        Route::get('/', [DesignsApiController::class, 'index'])->name('index');
        Route::get('{design}', [DesignsApiController::class, 'show'])->name('show');
        Route::post('{design}/comments', [DesignsApiController::class, 'comment'])->middleware('throttle:30,1')->name('comments.store');
        Route::patch('{design}/status', [DesignsApiController::class, 'status'])->name('status');
    });

// The public privacy policy for the Google OAuth consent screen: no auth, and on even while the module is off.
Route::middleware('web')->get('gizlilik', PrivacyPolicyController::class)->name('marmara.privacy');
