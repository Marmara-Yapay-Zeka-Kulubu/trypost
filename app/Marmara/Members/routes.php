<?php

declare(strict_types=1);

use App\Http\Middleware\App\EnsureAccountReady;
use App\Http\Middleware\App\EnsureHasWorkspace;
use App\Marmara\Members\Http\MemberPostStatsController;
use Illuminate\Support\Facades\Route;

/*
| Marmara AI fork: per member post counts for the Insights page
| (app/Marmara/README.md, Members). Loaded by MembersServiceProvider.
*/

Route::middleware(['web', 'auth', EnsureAccountReady::class, EnsureHasWorkspace::class])
    ->get('insights/members', MemberPostStatsController::class)
    ->middleware('throttle:60,1')
    ->name('marmara.members.posts');
