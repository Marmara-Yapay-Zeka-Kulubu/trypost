<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Publishing;

use App\Dto\MediaItem;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Marmara\Zernio\Exceptions\ZernioPublishException;
use App\Marmara\Zernio\ZernioTikTokPayload;
use App\Models\Post;
use App\Services\Social\ContentSanitizer;
use App\Services\Social\TikTokPublisher;
use App\Support\PostPlatformMetaRules;
use Illuminate\Support\Collection;

/**
 * TikTok through Zernio: the privacy level the user picked, a video or up
 * to 35 photos, `tiktokSettings` from ZernioTikTokPayload. A published post
 * keeps `zernio:{id}` until TikTok reports the public video id.
 *
 * @see https://docs.zernio.com/platforms/tiktok/posts
 */
class ZernioTikTokChannel implements ZernioChannel
{
    /** TikTok's photo carousel limit. */
    private const int MAX_PHOTOS = 35;

    public function __construct(
        private readonly ZernioTikTokPayload $payload,
        private readonly ZernioMediaHost $media,
    ) {}

    public function platform(): Platform
    {
        return Platform::TikTok;
    }

    public function payload(Post $post, string $zernioAccountId, string $reference): array
    {
        $violation = PostPlatformMetaRules::requiredMetaViolation(Platform::TikTok, $post->meta);

        if ($violation !== null) {
            throw ZernioPublishException::invalid($violation[1], ErrorCategory::ContentPolicy);
        }

        [$mediaItems, $isPhotoPost, $video] = $this->mediaFor($post);

        return $this->payload->build(
            $post,
            $zernioAccountId,
            app(ContentSanitizer::class)->forPost($post),
            $mediaItems,
            $isPhotoPost,
            $video,
            $reference,
        );
    }

    public function result(Post $post, string $zernioPostId, array $target): array
    {
        $account = $post->socialAccount;
        $videoId = self::videoIdOf($target);

        if ($videoId === null) {
            return [
                'id' => ZernioPostFlow::POST_ID_PREFIX.$zernioPostId,
                'url' => TikTokPublisher::postUrl($account),
            ];
        }

        return [
            'id' => $videoId,
            'url' => TikTokPublisher::postUrl($account, $videoId) ?? self::urlOf($target),
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     */
    public static function videoIdOf(array $target): ?string
    {
        $platformPostId = (string) data_get($target, 'platformPostId', '');

        if ($platformPostId !== '' && ctype_digit($platformPostId)) {
            return $platformPostId;
        }

        $url = self::urlOf($target);

        if ($url !== null && preg_match('#/(?:video|photo)/(\d+)#', $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * The API calls it platformPostUrl, the webhooks publishedUrl.
     *
     * @param  array<string, mixed>  $target
     */
    private static function urlOf(array $target): ?string
    {
        foreach (['platformPostUrl', 'publishedUrl', 'url'] as $key) {
            $url = data_get($target, $key);

            if (is_string($url) && str_starts_with($url, 'https://') && str_contains($url, 'tiktok.com/')) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @return array{0: list<array{type: string, url: string}>, 1: bool, 2: ?MediaItem}
     */
    private function mediaFor(Post $post): array
    {
        /** @var Collection<int, MediaItem> $media */
        $media = $post->mediaItems;

        if ($media->isEmpty()) {
            throw ZernioPublishException::invalid('TikTok requires media (video or photos) to publish.');
        }

        $first = $media->first();

        if ($first->isVideo()) {
            return [[['type' => 'video', 'url' => $this->media->url($first, 'video/mp4')]], false, $first];
        }

        if (! $first->isImage()) {
            throw ZernioPublishException::invalid('TikTok only supports video or image content.');
        }

        $images = $media->filter(fn (MediaItem $item): bool => $item->isImage())->values();

        if ($images->count() > self::MAX_PHOTOS) {
            throw ZernioPublishException::invalid('TikTok photo posts take at most '.self::MAX_PHOTOS.' photos.');
        }

        $items = $images
            ->map(fn (MediaItem $image): array => ['type' => 'image', 'url' => $this->media->url($image, 'image/jpeg')])
            ->all();

        return [array_values($items), true, null];
    }
}
