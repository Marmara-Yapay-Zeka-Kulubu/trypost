<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Connect;

use App\Http\Controllers\Auth\FacebookController;

/**
 * Upstream's FacebookController with the inbox scopes added (AddsInboxScopes).
 * Bound in place of it by InboxServiceProvider.
 */
class InboxFacebookController extends FacebookController
{
    use AddsInboxScopes;
}
