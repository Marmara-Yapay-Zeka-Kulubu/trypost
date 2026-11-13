<?php

declare(strict_types=1);

namespace App\Marmara\Hooks;

use App\Models\Post;

/**
 * Marmara AI fork extension point in PublishToSocialPlatform::handle() and
 * ResolveTikTokVideoId::handle().
 *
 * This default keeps upstream behaviour: the job checks the channel's native
 * connection (disconnected, token expired, missing scopes). A module that
 * publishes a channel through another service rebinds this class in the
 * container and checks that service instead (see app/Marmara/README.md).
 */
class SocialAccountPublishGuard
{
    public function check(Post $post): PublishGuardVerdict
    {
        return PublishGuardVerdict::native();
    }

    /**
     * Whether a published post's follow-up calls (the TikTok video id lookup)
     * need the channel's native connection. Upstream: always.
     */
    public function requiresConnectedChannel(Post $post): bool
    {
        return true;
    }
}
