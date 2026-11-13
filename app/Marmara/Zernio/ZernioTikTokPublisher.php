<?php

declare(strict_types=1);

namespace App\Marmara\Zernio;

use App\Dto\MediaItem;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Marmara\Zernio\Exceptions\ZernioPublishException;
use App\Marmara\Zernio\Models\ZernioPublication;
use App\Models\Post;
use App\Services\Social\ContentSanitizer;
use App\Services\Social\TikTokPublisher;
use App\Support\PostPlatformMetaRules;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Publishes the TikTok posts of mapped accounts through Zernio, whose TikTok
 * app is audited for public posting, and leaves every other TikTok account on
 * the native publisher (parent).
 *
 * It answers PublishToSocialPlatform exactly like TikTokPublisher: an
 * `id`/`url` array on success, PlatformUnavailableException while TikTok is
 * still processing (the job checks again later), and a SocialPublishException
 * when the post failed. TryPost's scheduler decides when a post goes out, so
 * every Zernio post is created with `publishNow`.
 *
 * When TikTok has not reported the public video id yet, the post keeps
 * `zernio:{zernio post id}` as its platform_post_id, like the native
 * publish_id, and ResolveTikTokVideoId later asks publicVideoId() for it.
 */
class ZernioTikTokPublisher extends TikTokPublisher
{
    /**
     * A create request whose answer was lost is sent again only this long
     * after the first one: Zernio keeps an Idempotency-Key for 24 hours.
     */
    private const int IDEMPOTENCY_SAFE_HOURS = 23;

    public const string POST_ID_PREFIX = 'zernio:';

    /** TikTok's photo carousel limit. */
    private const int MAX_PHOTOS = 35;

    /** @var list<string> */
    private const array PUBLISHED_STATUSES = ['published'];

    /** @var list<string> */
    private const array FAILED_STATUSES = ['failed', 'cancelled', 'canceled', 'error'];

    public function __construct(
        private readonly ZernioConfig $config,
        private readonly ZernioClient $client,
        private readonly ZernioTikTokPayload $payload,
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

        $attempt = ZernioPublication::query()->where('post_id', $post->id)->first();

        if ($attempt !== null && ! $attempt->isDefinitivelyFailed()) {
            return match (true) {
                $attempt->last_status === ZernioPublication::STATUS_NEEDS_MANUAL_CHECK => throw $this->needsManualCheck($attempt),
                $attempt->zernio_post_id !== null => $this->resume($post, $attempt, $zernioAccountId),
                $attempt->last_status === ZernioPublication::STATUS_UNKNOWN
                    && $attempt->first_attempt_at->lte(now()->subHours(self::IDEMPOTENCY_SAFE_HOURS)) => $this->recoverLostCreate($post, $attempt, $zernioAccountId),
                default => $this->create($post, $attempt, $zernioAccountId),
            };
        }

        $attempt ??= new ZernioPublication(['post_id' => $post->id]);
        $attempt->fill([
            'zernio_account_id' => $zernioAccountId,
            'zernio_post_id' => null,
            'idempotency_key' => (string) Str::uuid(),
            'first_attempt_at' => now(),
            'last_attempt_at' => null,
            'last_status' => ZernioPublication::STATUS_PREPARING,
            'last_error' => null,
        ])->save();

        return $this->create($post, $attempt, $zernioAccountId);
    }

    /**
     * Sends the create request of the attempt. A request already sent whose
     * answer was lost is sent again with the same Idempotency-Key, so Zernio
     * answers with the post it created instead of a second one.
     *
     * @return array{id: string, url: ?string}
     */
    private function create(Post $post, ZernioPublication $attempt, string $zernioAccountId): array
    {
        $this->ensurePrivacyLevel($post);

        [$mediaItems, $isPhotoPost, $video] = $this->mediaFor($post);

        $payload = $this->payload->build(
            $post,
            $zernioAccountId,
            app(ContentSanitizer::class)->forPost($post),
            $mediaItems,
            $isPhotoPost,
            $video,
            $attempt->reference(),
        );

        // first_attempt_at is when the first create request left; it never
        // moves afterwards, so a lost answer is not resent past Zernio's
        // 24-hour idempotency window.
        if ($attempt->last_status === ZernioPublication::STATUS_PREPARING) {
            $attempt->first_attempt_at = now();
        }

        $attempt->fill([
            'last_status' => ZernioPublication::STATUS_UNKNOWN,
            'last_attempt_at' => now(),
        ])->save();

        try {
            $response = $this->client->createPost($payload, $attempt->idempotency_key);
        } catch (ConnectionException $e) {
            $attempt->update(['last_error' => $e->getMessage()]);

            throw $this->unavailable('Zernio did not answer the publish request: '.$e->getMessage());
        }

        if ($response->failed()) {
            if ($response->serverError() || ($response->status() === 409 && data_get($response->json(), 'code') === 'idempotency_conflict')) {
                $attempt->update(['last_error' => "HTTP {$response->status()}"]);

                throw $this->unavailable("Zernio create answered HTTP {$response->status()}.", $response);
            }

            $exception = ZernioPublishException::fromApiResponse($response);
            $attempt->update(['last_status' => ZernioPublication::STATUS_REJECTED, 'last_error' => $exception->userMessage]);

            throw $exception;
        }

        $zernioPost = (array) (data_get($response->json(), 'post') ?? []);
        $zernioPostId = $this->postIdOf($zernioPost);

        if ($zernioPostId === null) {
            $attempt->update(['last_status' => ZernioPublication::STATUS_NEEDS_MANUAL_CHECK, 'last_error' => 'Zernio answered without a post id.']);

            throw $this->needsManualCheck($attempt);
        }

        $attempt->update(['zernio_post_id' => $zernioPostId, 'last_status' => ZernioPublication::STATUS_PROCESSING]);

        Log::info('Marmara Zernio: TikTok post created', [
            'post_id' => $post->id,
            'zernio_post_id' => $zernioPostId,
            'http_status' => $response->status(),
        ]);

        return $this->settle($post, $attempt, $zernioPost, $zernioAccountId, $response->body());
    }

    /**
     * The create request of an attempt older than Zernio's idempotency window
     * got no answer: it is never sent again. The post is looked up by the
     * reference the request carried; when it cannot be found, an operator
     * decides (`marmara:zernio:release`).
     *
     * @return array{id: string, url: ?string}
     */
    private function recoverLostCreate(Post $post, ZernioPublication $attempt, string $zernioAccountId): array
    {
        $found = $this->findByReference($attempt, $zernioAccountId);

        if ($found === null) {
            $attempt->update([
                'last_status' => ZernioPublication::STATUS_NEEDS_MANUAL_CHECK,
                'last_attempt_at' => now(),
                'last_error' => 'The create request got no answer and Zernio has no post with its reference.',
            ]);

            throw $this->needsManualCheck($attempt);
        }

        $zernioPostId = (string) $this->postIdOf($found);
        $attempt->update(['zernio_post_id' => $zernioPostId, 'last_status' => ZernioPublication::STATUS_PROCESSING, 'last_attempt_at' => now()]);

        return $this->settle($post, $attempt, $found, $zernioAccountId, (string) json_encode($found));
    }

    /**
     * The Zernio post carrying the attempt's reference (tag, or metadata as a
     * fallback), or null when Zernio has none or cannot tell.
     *
     * @return array<string, mixed>|null
     */
    private function findByReference(ZernioPublication $attempt, string $zernioAccountId): ?array
    {
        $reference = $attempt->reference();
        $queries = [
            ['tags' => $reference, 'accountId' => $zernioAccountId, 'limit' => 50],
            [
                'accountId' => $zernioAccountId,
                'platform' => 'tiktok',
                'updatedSince' => $attempt->first_attempt_at->subMinutes(10)->toIso8601ZuluString(),
                'limit' => 100,
            ],
        ];

        foreach ($queries as $query) {
            try {
                $response = $this->client->listPosts($query);
            } catch (ConnectionException $e) {
                throw $this->unavailable('Zernio did not answer the post lookup: '.$e->getMessage());
            }

            if ($response->serverError() || $response->status() === 429) {
                throw $this->unavailable("Zernio post lookup answered HTTP {$response->status()}.", $response);
            }

            if ($response->failed()) {
                continue;
            }

            $match = collect((array) data_get($response->json(), 'posts', []))->first(
                fn (mixed $candidate): bool => is_array($candidate)
                    && $this->postIdOf($candidate) !== null
                    && (data_get($candidate, 'metadata.trypost_reference') === $reference
                        || in_array($reference, (array) data_get($candidate, 'tags', []), true)),
            );

            if (is_array($match)) {
                return $match;
            }
        }

        return null;
    }

    private function needsManualCheck(ZernioPublication $attempt): ZernioPublishException
    {
        return ZernioPublishException::invalid(
            'TikTok (via Zernio): the publish request sent '.$attempt->first_attempt_at->toIso8601String()
            .' got no answer and is past Zernio\'s 24-hour duplicate protection, so it was not sent again.'
            .' Needs a manual check in Zernio (reference '.$attempt->reference().'). To send it anyway, run'
            .' "php artisan marmara:zernio:release '.$attempt->post_id.'" and retry the post.',
            ErrorCategory::Unknown,
        );
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

        $target = $this->tiktokTarget(
            (array) (data_get($response->json(), 'post') ?? $response->json() ?? []),
            $this->config->zernioAccountFor($post->socialAccount),
        );

        return $target === null ? null : $this->videoIdOf($target);
    }

    /**
     * The Zernio post id stored as a TryPost platform_post_id, if it is one.
     */
    public static function zernioPostId(?string $platformPostId): ?string
    {
        if ($platformPostId === null || ! str_starts_with($platformPostId, self::POST_ID_PREFIX)) {
            return null;
        }

        $id = substr($platformPostId, strlen(self::POST_ID_PREFIX));

        return $id !== '' ? $id : null;
    }

    /**
     * The attempt already has a Zernio post: its status decides, nothing is
     * created again (a posts:retry included).
     *
     * @return array{id: string, url: ?string}
     */
    private function resume(Post $post, ZernioPublication $attempt, string $zernioAccountId): array
    {
        $zernioPostId = (string) $attempt->zernio_post_id;

        try {
            $response = $this->client->getPost($zernioPostId);
        } catch (ConnectionException) {
            throw $this->pending($zernioPostId, null);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw $this->pending($zernioPostId, null);
        }

        if ($response->status() === 404) {
            $attempt->update([
                'last_status' => ZernioPublication::STATUS_FAILED,
                'last_attempt_at' => now(),
                'last_error' => 'Zernio no longer has this post.',
            ]);

            throw ZernioPublishException::invalid("TikTok (via Zernio): Zernio no longer has post {$zernioPostId}. Retry the post to send it again.", ErrorCategory::Unknown);
        }

        if ($response->failed()) {
            throw ZernioPublishException::fromApiResponse($response);
        }

        $zernioPost = (array) (data_get($response->json(), 'post') ?? $response->json() ?? []);

        return $this->settle($post, $attempt, $zernioPost, $zernioAccountId, $response->body());
    }

    /**
     * @param  array<string, mixed>  $zernioPost
     * @return array{id: string, url: ?string}
     */
    private function settle(Post $post, ZernioPublication $attempt, array $zernioPost, string $zernioAccountId, string $rawResponse): array
    {
        $zernioPostId = (string) $attempt->zernio_post_id;
        $target = $this->tiktokTarget($zernioPost, $zernioAccountId);
        $status = strtolower((string) (data_get($target, 'status') ?? data_get($zernioPost, 'status') ?? ''));

        if (in_array($status, self::PUBLISHED_STATUSES, true)) {
            $attempt->update(['last_status' => ZernioPublication::STATUS_PUBLISHED, 'last_attempt_at' => now(), 'last_error' => null]);

            return $this->result($post, $zernioPostId, $target ?? []);
        }

        if (in_array($status, self::FAILED_STATUSES, true)) {
            $exception = ZernioPublishException::fromFailedTarget($target ?? $zernioPost, $rawResponse);
            $attempt->update(['last_status' => ZernioPublication::STATUS_FAILED, 'last_attempt_at' => now(), 'last_error' => $exception->userMessage]);

            throw $exception;
        }

        $attempt->update(['last_status' => ZernioPublication::STATUS_PROCESSING, 'last_attempt_at' => now()]);

        Log::info('Marmara Zernio: TikTok post still processing', [
            'post_id' => $post->id,
            'zernio_post_id' => $zernioPostId,
            'status' => $status,
            'seconds_since_first_attempt' => (int) $attempt->first_attempt_at->diffInSeconds(now()),
        ]);

        throw $this->pending($zernioPostId, $status !== '' ? $status : null);
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array{id: string, url: ?string}
     */
    private function result(Post $post, string $zernioPostId, array $target): array
    {
        $account = $post->socialAccount;
        $videoId = $this->videoIdOf($target);

        if ($videoId === null) {
            return [
                'id' => self::POST_ID_PREFIX.$zernioPostId,
                'url' => self::postUrl($account),
            ];
        }

        return [
            'id' => $videoId,
            'url' => self::postUrl($account, $videoId) ?? $this->tiktokUrlOf($target),
        ];
    }

    /**
     * The TikTok entry of a Zernio post: the one for our account, else the
     * first TikTok entry. `accountId` may come back as an id or as an object.
     *
     * @param  array<string, mixed>  $zernioPost
     * @return array<string, mixed>|null
     */
    private function tiktokTarget(array $zernioPost, ?string $zernioAccountId): ?array
    {
        $targets = collect((array) data_get($zernioPost, 'platforms', []))
            ->filter(fn (mixed $target): bool => is_array($target) && strtolower((string) data_get($target, 'platform')) === 'tiktok')
            ->values();

        $own = $targets->first(function (array $target) use ($zernioAccountId): bool {
            $accountId = data_get($target, 'accountId');
            $accountId = is_array($accountId) ? (data_get($accountId, '_id') ?? data_get($accountId, 'id')) : $accountId;

            return $zernioAccountId !== null && (string) $accountId === $zernioAccountId;
        });

        return $own ?? $targets->first();
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function videoIdOf(array $target): ?string
    {
        $platformPostId = (string) data_get($target, 'platformPostId', '');

        if ($platformPostId !== '' && ctype_digit($platformPostId)) {
            return $platformPostId;
        }

        $url = $this->tiktokUrlOf($target);

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
    private function tiktokUrlOf(array $target): ?string
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
     * @param  array<string, mixed>  $zernioPost
     */
    private function postIdOf(array $zernioPost): ?string
    {
        $id = data_get($zernioPost, '_id') ?? data_get($zernioPost, 'id');

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    private function ensurePrivacyLevel(Post $post): void
    {
        $violation = PostPlatformMetaRules::requiredMetaViolation(Platform::TikTok, $post->meta);

        if ($violation !== null) {
            throw ZernioPublishException::invalid($violation[1], ErrorCategory::ContentPolicy);
        }
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
            return [[['type' => 'video', 'url' => $this->hostedUrl($first, 'video/mp4')]], false, $first];
        }

        if (! $first->isImage()) {
            throw ZernioPublishException::invalid('TikTok only supports video or image content.');
        }

        $images = $media->filter(fn (MediaItem $item): bool => $item->isImage())->values();

        if ($images->count() > self::MAX_PHOTOS) {
            throw ZernioPublishException::invalid('TikTok photo posts take at most '.self::MAX_PHOTOS.' photos.');
        }

        $items = $images
            ->map(fn (MediaItem $image): array => ['type' => 'image', 'url' => $this->hostedUrl($image, 'image/jpeg')])
            ->all();

        return [array_values($items), true, null];
    }

    /**
     * The URL Zernio fetches the file from: TryPost's own public URL, or a
     * copy uploaded to Zernio's storage through a presigned URL.
     *
     * @see https://docs.zernio.com/guides/media-uploads
     */
    private function hostedUrl(MediaItem $item, string $fallbackMime): string
    {
        if ($this->config->mediaMode() === ZernioConfig::MEDIA_MODE_URL) {
            return $item->url;
        }

        $contentType = filled($item->mime_type) ? (string) $item->mime_type : $fallbackMime;
        $filename = filled($item->original_filename) ? (string) $item->original_filename : basename($item->path);
        $tempFile = tempnam(sys_get_temp_dir(), 'zernio_media_');

        try {
            try {
                $download = Http::sink($tempFile)->timeout($this->config->timeout() * 5)->get($item->url);
            } catch (ConnectionException $e) {
                throw $this->unavailable('Could not download the media for Zernio: '.$e->getMessage());
            }

            if ($download->failed()) {
                throw $this->unavailable("Could not download the media for Zernio (HTTP {$download->status()}).");
            }

            $size = filesize($tempFile) ?: null;

            try {
                $presign = $this->client->presignMedia($filename, $contentType, $size);
            } catch (ConnectionException $e) {
                throw $this->unavailable('Zernio did not answer the upload request: '.$e->getMessage());
            }

            if ($presign->failed()) {
                if ($presign->serverError() || $presign->status() === 429) {
                    throw $this->unavailable("Zernio refused the upload URL (HTTP {$presign->status()}).", $presign);
                }

                throw ZernioPublishException::fromApiResponse($presign);
            }

            $uploadUrl = data_get($presign->json(), 'uploadUrl');
            $publicUrl = data_get($presign->json(), 'publicUrl');

            if (! is_string($uploadUrl) || ! is_string($publicUrl) || $uploadUrl === '' || $publicUrl === '') {
                throw ZernioPublishException::misconfigured('Zernio did not return an upload URL.');
            }

            try {
                $upload = $this->client->uploadToPresignedUrl($uploadUrl, $tempFile, $contentType);
            } catch (ConnectionException $e) {
                throw $this->unavailable('Uploading the media to Zernio failed: '.$e->getMessage());
            }

            if ($upload->failed()) {
                throw $this->unavailable("Uploading the media to Zernio failed (HTTP {$upload->status()}).");
            }

            return $publicUrl;
        } finally {
            @unlink($tempFile);
        }
    }

    private function unavailable(string $message, ?Response $response = null): PlatformUnavailableException
    {
        $retryAfter = (int) ($response?->header('Retry-After') ?? 0);

        return new PlatformUnavailableException(
            message: $message,
            httpStatus: $response?->status(),
            context: ['zernio_detail' => $message],
            retryDelaySeconds: $retryAfter > 0 ? $retryAfter : null,
        );
    }

    private function pending(string $zernioPostId, ?string $status): PlatformUnavailableException
    {
        return new PlatformUnavailableException(
            message: "Zernio is still publishing post {$zernioPostId} to TikTok",
            context: array_filter(['zernio_status' => $status]),
            retryDelaySeconds: $this->config->statusPollSeconds(),
            maxRetries: $this->config->statusMaxChecks(),
        );
    }
}
