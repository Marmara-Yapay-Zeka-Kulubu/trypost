<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Connect;

use App\Http\Controllers\Auth\InstagramFacebookController;

/**
 * Upstream's InstagramFacebookController with the inbox scopes added (AddsInboxScopes).
 * Bound in place of it by InboxServiceProvider.
 */
class InboxInstagramFacebookController extends InstagramFacebookController
{
    use AddsInboxScopes;
}
