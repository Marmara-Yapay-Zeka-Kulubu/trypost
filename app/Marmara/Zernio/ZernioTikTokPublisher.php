<?php

declare(strict_types=1);

namespace App\Marmara\Zernio;

use App\Marmara\Zernio\Exceptions\ZernioPublishException;
use App\Marmara\Zernio\Publishing\ZernioPostFlow;
use App\Marmara\Zernio\Publishing\ZernioTikTokChannel;
use App\Models\Post;
use App\Services\Social\TikTokPublisher;
use Illuminate\Http\Client\ConnectionException;

/**
 * Publishes the TikTok posts of mapped accounts through Zernio, whose TikTok
 * app is audited for public posting, and leaves every other TikTok account on
 * the native publisher (parent). The publish itself (attempts, idempotency,
 * status polling) is ZernioPostFlow's; the TikTok specifics are
 * ZernioTikTokChannel's.
 *
 * When TikTok has not reported the public video id yet, the post keeps
 * `zernio:{zernio post id}` as its platform_post_id, like the native
 * publish_id, and ResolveTikTokVideoId later asks publicVideoId() for it.
 */
class ZernioTikTokPublisher extends TikTokPublisher
{
    public const string POST_ID_PREFIX = ZernioPostFlow::POST_ID_PREFIX;

    public function __construct(
        private readonly ZernioConfig $config,
        private readonly ZernioClient $client,
        private readonly ZernioPostFlow $flow,
        private readonly ZernioTikTokChannel $channel,
    ) {
        parent::__construct();
    }

    public function publish(Post $post): array
    {
        $zernioAccountId = $this->config->zernioAccountFor($post->socialAccount);

        if ($zernioAccountId === null) {
            return parent::publish($post);
        }

        $this->validateContentLength($post);

        return $this->flow->publish($post, $zernioAccountId, $this->channel);
    }

    /**
     * The public TikTok video id of a post Zernio published, once TikTok
     * reported it. Posts published natively keep the native lookup.
     */
    public function publicVideoId(Post $post): ?string
    {
        $zernioPostId = self::zernioPostId($post->platform_post_id);

        if ($zernioPostId === null) {
            return parent::publicVideoId($post);
        }

        try {
            $response = $this->client->getPost($zernioPostId);
        } catch (ConnectionException|ZernioPublishException) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $target = ZernioPostFlow::target(
            (array) (data_get($response->json(), 'post') ?? $response->json() ?? []),
            'tiktok',
            $this->config->zernioAccountFor($post->socialAccount),
        );

        return $target === null ? null : ZernioTikTokChannel::videoIdOf($target);
    }

    /**
     * The Zernio post id stored as a TryPost platform_post_id, if it is one.
     */
    public static function zernioPostId(?string $platformPostId): ?string
    {
        return ZernioPostFlow::zernioPostId($platformPostId);
    }
}
