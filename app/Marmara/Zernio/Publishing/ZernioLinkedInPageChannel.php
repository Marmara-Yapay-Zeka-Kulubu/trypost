<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Publishing;

use App\Dto\MediaItem;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Marmara\Zernio\Exceptions\ZernioPublishException;
use App\Models\Post;
use App\Services\Social\ContentSanitizer;
use App\Support\ThreadReplies;
use Illuminate\Support\Collection;

/**
 * A LinkedIn company page through Zernio, with the media rules of TryPost's
 * native LinkedIn publisher: one document (PDF, PPT(X), DOC(X)), or two or
 * more images as a multi-image post (Zernio: up to 20), or one video or one
 * image; a text post gets LinkedIn's link preview unless the user turned it
 * off. TryPost's first comment (its one thread reply) becomes Zernio's
 * `firstComment`.
 *
 * @see https://docs.zernio.com/platforms/linkedin
 */
class ZernioLinkedInPageChannel implements ZernioChannel
{
    private const int MAX_IMAGES = 20;

    public function __construct(private readonly ZernioMediaHost $media) {}

    public function platform(): Platform
    {
        return Platform::LinkedInPage;
    }

    public function payload(Post $post, string $zernioAccountId, string $reference): array
    {
        $firstComment = $this->firstComment($post);
        [$mediaItems, $documentTitle] = $this->mediaFor($post);
        $organizationId = data_get($post->socialAccount?->meta, 'organization_id');

        $settings = array_filter([
            'organizationUrn' => filled($organizationId) ? "urn:li:organization:{$organizationId}" : null,
            'documentTitle' => $documentTitle,
            'firstComment' => $firstComment,
            'disableLinkPreview' => $mediaItems === [] && ! $post->attachesLinkPreview() ? true : null,
        ], fn (mixed $value): bool => $value !== null);

        $target = ['platform' => 'linkedin', 'accountId' => $zernioAccountId];

        if ($settings !== []) {
            $target['platformSpecificData'] = $settings;
        }

        return array_filter([
            'content' => (string) (app(ContentSanitizer::class)->forPost($post) ?? ''),
            'mediaItems' => $mediaItems,
            'platforms' => [$target],
            'publishNow' => true,
            // Lets a lost create answer be found again (GET /posts?tags=...).
            'tags' => [$reference],
            'metadata' => [
                'trypost_post_id' => (string) $post->id,
                'trypost_reference' => $reference,
            ],
        ], fn (mixed $value): bool => $value !== []);
    }

    public function result(Post $post, string $zernioPostId, array $target): array
    {
        $postId = data_get($target, 'platformPostId');
        $postId = is_string($postId) && $postId !== '' ? $postId : null;
        $url = data_get($target, 'platformPostUrl') ?? data_get($target, 'publishedUrl');
        $url = is_string($url) && str_starts_with($url, 'https://') ? $url : null;

        return [
            'id' => $postId ?? ZernioPostFlow::POST_ID_PREFIX.$zernioPostId,
            // The same permalink the native publisher builds from the post URN.
            'url' => $url ?? ($postId !== null && str_starts_with($postId, 'urn:li:') ? "https://www.linkedin.com/feed/update/{$postId}" : null),
        ];
    }

    /**
     * @return array{0: list<array<string, string>>, 1: ?string}
     */
    private function mediaFor(Post $post): array
    {
        /** @var Collection<int, MediaItem> $media */
        $media = $post->mediaItems;
        $platform = $this->platform()->value;

        if ($media->isEmpty()) {
            return [[], null];
        }

        $document = $media->first(fn (MediaItem $item): bool => $item->isDocument());

        if ($document !== null) {
            $title = data_get($post->meta, 'document_title');
            $title = filled($title) ? (string) $title : ($document->original_filename ?: 'Document');

            return [[['type' => 'document', 'url' => $this->media->url($document, 'application/pdf', $platform), 'title' => $title]], $title];
        }

        $images = $media->filter(fn (MediaItem $item): bool => $item->isImage())->values();

        if ($images->count() >= 2) {
            if ($images->count() > self::MAX_IMAGES) {
                throw ZernioPublishException::invalid('LinkedIn posts take at most '.self::MAX_IMAGES.' images.', platform: $platform);
            }

            return [$images->map(fn (MediaItem $image): array => $this->image($image))->all(), null];
        }

        $first = $media->first();

        if ($first->isVideo()) {
            return [[['type' => 'video', 'url' => $this->media->url($first, 'video/mp4', $platform)]], null];
        }

        if ($first->isImage()) {
            return [[$this->image($first)], null];
        }

        throw ZernioPublishException::invalid('LinkedIn only supports images, a video or a document.', platform: $platform);
    }

    /**
     * @return array<string, string>
     */
    private function image(MediaItem $image): array
    {
        return array_filter([
            'type' => 'image',
            'url' => $this->media->url($image, 'image/jpeg', $this->platform()->value),
            'altText' => (string) $image->altTextFor($this->platform()),
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * TryPost publishes a LinkedIn post's thread replies as comments; Zernio
     * takes one text-only first comment. More, or one with media, is refused
     * before anything is sent rather than published without them.
     */
    private function firstComment(Post $post): ?string
    {
        $replies = ThreadReplies::supports($post->platform) ? ThreadReplies::of($post->meta) : [];

        if ($replies === []) {
            return null;
        }

        $platform = $this->platform()->value;

        if (count($replies) > 1 || ThreadReplies::mediaItems($replies[0])->isNotEmpty()) {
            throw ZernioPublishException::invalid(
                ZernioPublishException::prefix($platform).'only one text comment can be published with the post.',
                ErrorCategory::ContentPolicy,
                $platform,
            );
        }

        $text = trim(app(ContentSanitizer::class)->sanitize((string) $replies[0]['text'], $post->platform));

        return $text !== '' ? $text : null;
    }
}
