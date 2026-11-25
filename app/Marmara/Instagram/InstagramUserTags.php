<?php

declare(strict_types=1);

namespace App\Marmara\Instagram;

use App\Dto\MediaItem;
use App\Enums\Media\Type as MediaType;
use App\Enums\PostPlatform\ContentType;
use App\Support\PostMediaRules;

/**
 * Marmara AI fork: Instagram people tags (`media.*.meta.user_tags`) on
 * stories and reels as well as feed images.
 *
 * Meta's content publishing API takes `user_tags` on feed images (and
 * carousel images) with `x` and `y` required, and on image stories, video
 * stories and reels with only `username` required (stories since
 * July 9, 2025). TryPost's `MediaItem::userTags()` keeps the feed shape (tags
 * without a position are left out); this class gives the story / reel shape
 * and the check that a feed image's tags all have a position.
 *
 * @see https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/media
 */
final class InstagramUserTags
{
    /**
     * Content types that take people tags.
     */
    public static function supports(ContentType $contentType): bool
    {
        return in_array($contentType, [ContentType::InstagramFeed, ContentType::InstagramStory, ContentType::InstagramReel], true);
    }

    /**
     * Whether every tag of the content type needs `x` and `y` (feed images;
     * a feed video publishes as a Reel and needs none).
     */
    public static function needsPosition(ContentType $contentType): bool
    {
        return $contentType === ContentType::InstagramFeed;
    }

    /**
     * The tags of a story or reel as Meta takes them there: the username
     * only (without `@`), at most 20. A position given in the editor is not
     * sent: it means nothing on a video, and a story places the sticker
     * itself.
     *
     * @return list<array{username: string}>
     */
    public static function usernames(MediaItem $media): array
    {
        $usernames = [];

        foreach ((array) data_get($media->meta, 'user_tags', []) as $tag) {
            $username = is_array($tag) ? data_get($tag, 'username') : null;

            if (! is_string($username)) {
                continue;
            }

            $username = ltrim(trim($username), '@');

            if ($username === '' || in_array($username, $usernames, true)) {
                continue;
            }

            $usernames[] = $username;
        }

        return array_map(
            fn (string $username): array => ['username' => $username],
            array_slice($usernames, 0, PostMediaRules::USER_TAGS_MAX),
        );
    }

    /**
     * Whether an image of the given (stored or submitted) media has a tag
     * without a numeric `x` and `y`. Videos are not checked: a feed video
     * publishes as a Reel.
     *
     * @param  array<int, mixed>  $media
     */
    public static function anyImageTagWithoutPosition(array $media): bool
    {
        foreach ($media as $item) {
            if (self::isVideo($item)) {
                continue;
            }

            foreach ((array) data_get($item, 'meta.user_tags', []) as $tag) {
                if (! is_numeric(data_get($tag, 'x')) || ! is_numeric(data_get($tag, 'y'))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The message for a feed image tag without a position, from existing
     * translations ("The Tagged people (x, y) field is required.").
     */
    public static function positionRequiredMessage(): string
    {
        return trans('validation.required', ['attribute' => trans('posts.edit.user_tags.label').' (x, y)']);
    }

    private static function isVideo(mixed $item): bool
    {
        $type = data_get($item, 'type');

        if ($type instanceof MediaType) {
            return $type === MediaType::Video;
        }

        if (is_string($type) && $type !== '') {
            return $type === MediaType::Video->value;
        }

        return str_starts_with((string) data_get($item, 'mime_type', ''), 'video/');
    }
}
