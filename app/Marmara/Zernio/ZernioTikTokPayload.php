<?php

declare(strict_types=1);

namespace App\Marmara\Zernio;

use App\Dto\MediaItem;
use App\Models\Post;

/**
 * Maps a TryPost TikTok post's meta to Zernio's `tiktokSettings`, keeping the
 * same rules as App\Services\Social\TikTokPublisher: interaction toggles are
 * off unless the user turned them on, and privacy_level is only the one the
 * user picked (TikTok UX guideline: no default).
 *
 * @see https://docs.zernio.com/platforms/tiktok/posts
 */
class ZernioTikTokPayload
{
    /**
     * @param  list<array{type: string, url: string}>  $mediaItems
     * @return array<string, mixed>
     */
    public function build(Post $post, string $zernioAccountId, ?string $caption, array $mediaItems, bool $isPhotoPost, ?MediaItem $video, string $reference): array
    {
        $meta = $post->meta ?? [];

        $settings = [
            'privacy_level' => (string) data_get($meta, 'privacy_level'),
            'allow_comment' => (bool) data_get($meta, 'allow_comments', false),
            // The composer shows TikTok's preview and the music/branded
            // content declaration before a TikTok post can be scheduled.
            'content_preview_confirmed' => true,
            'express_consent_given' => true,
        ];

        if ($isPhotoPost) {
            $settings['media_type'] = 'photo';
            $settings['photo_cover_index'] = 0;
            $settings['description'] = $caption ?? '';

            if (data_get($meta, 'auto_add_music', false)) {
                $settings['auto_add_music'] = true;
            }
        } else {
            $settings['allow_duet'] = (bool) data_get($meta, 'allow_duet', false);
            $settings['allow_stitch'] = (bool) data_get($meta, 'allow_stitch', false);

            if (data_get($meta, 'is_aigc', false)) {
                $settings['is_aigc'] = true;
            }

            $coverOffsetMs = $video?->coverOffsetMs();

            if ($coverOffsetMs !== null) {
                $settings['video_cover_timestamp_ms'] = $coverOffsetMs;
            }
        }

        $settings = [...$settings, ...$this->commercialContent($meta)];

        $payload = [
            'mediaItems' => $mediaItems,
            'platforms' => [
                ['platform' => 'tiktok', 'accountId' => $zernioAccountId],
            ],
            'tiktokSettings' => $settings,
            'publishNow' => true,
            // Lets a lost create answer be found again (GET /posts?tags=...).
            'tags' => [$reference],
            'metadata' => [
                'trypost_post_id' => (string) $post->id,
                'trypost_reference' => $reference,
            ],
        ];

        // A carousel's `content` becomes TikTok's 90-character title; the
        // caption goes in `description`, as the native publisher sends it.
        if (! $isPhotoPost) {
            $payload['content'] = $caption ?? '';
        }

        return $payload;
    }

    /**
     * TryPost's two disclosure toggles as Zernio's commercial content fields.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function commercialContent(array $meta): array
    {
        $ownBrand = (bool) data_get($meta, 'brand_organic_toggle', false);
        $paidPartnership = (bool) data_get($meta, 'brand_content_toggle', false);

        return match (true) {
            $ownBrand && $paidPartnership => ['isBrandOrganicPost' => true, 'brandPartnerPromote' => true],
            $paidPartnership => ['commercialContentType' => 'brand_content'],
            $ownBrand => ['commercialContentType' => 'brand_organic'],
            default => [],
        };
    }
}
