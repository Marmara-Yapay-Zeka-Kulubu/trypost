<?php

declare(strict_types=1);

namespace App\Marmara\Hooks;

use App\Models\Post;

/**
 * Marmara AI fork extension point in PublishToSocialPlatform::handle().
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
}
