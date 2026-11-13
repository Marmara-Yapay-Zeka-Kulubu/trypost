<?php

declare(strict_types=1);

namespace App\Marmara\Zernio;

use App\Marmara\Zernio\Publishing\ZernioLinkedInPageChannel;
use App\Marmara\Zernio\Publishing\ZernioPostFlow;
use App\Models\Post;
use App\Services\Social\LinkedInPagePublisher;

/**
 * Publishes the posts of mapped LinkedIn company pages through Zernio (the
 * club's own LinkedIn app has no Community Management API access) and leaves
 * every other page on the native publisher (parent). Removing the page's
 * `linkedin-page:` mapping moves it back to the native path.
 */
class ZernioLinkedInPagePublisher extends LinkedInPagePublisher
{
    public function __construct(
        private readonly ZernioConfig $config,
        private readonly ZernioPostFlow $flow,
        private readonly ZernioLinkedInPageChannel $channel,
    ) {}

    public function publish(Post $post): array
    {
        $zernioAccountId = $this->config->zernioAccountFor($post->socialAccount);

        if ($zernioAccountId === null) {
            return parent::publish($post);
        }

        $this->validateContentLength($post);

        return $this->flow->publish($post, $zernioAccountId, $this->channel);
    }
}
