<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Connect;

use App\Http\Controllers\Auth\InstagramController;

/**
 * Upstream's InstagramController with the inbox scopes added (AddsInboxScopes).
 * Bound in place of it by InboxServiceProvider.
 */
class InboxInstagramController extends InstagramController
{
    use AddsInboxScopes;
}
