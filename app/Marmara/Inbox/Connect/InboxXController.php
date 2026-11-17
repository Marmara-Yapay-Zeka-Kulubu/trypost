<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Connect;

use App\Http\Controllers\Auth\XController;

/**
 * Upstream's XController with the inbox scopes added (AddsInboxScopes).
 * Bound in place of it by InboxServiceProvider.
 */
class InboxXController extends XController
{
    use AddsInboxScopes;
}
