<?php

declare(strict_types=1);

namespace App\Marmara\Zernio;

use App\Dto\MediaItem;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Marmara\Zernio\Exceptions\ZernioPublishException;
use App\Models\Post;
use App\Services\Social\ContentSanitizer;
use App\Services\Social\TikTokPublisher;
use App\Support\PostPlatformMetaRules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
    /** posts.error_context key of the in-flight Zernio publish. */
    public const string CHECKPOINT = 'marmara_zernio';

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

        $checkpoint = self::checkpoint($post->error_context);

        if (filled($checkpoint['post_id'] ?? null)) {
            return $this->resume($post, $checkpoint, $zernioAccountId);
        }

        $this->ensurePrivacyLevel($post);

        [$mediaItems, $isPhotoPost, $video] = $this->mediaFor($post);

        $payload = $this->payload->build(
            $post,
            $zernioAccountId,
            app(ContentSanitizer::class)->forPost($post),
            $mediaItems,
            $isPhotoPost,
            $video,
        );

        $idempotencyKey = is_string($checkpoint['idempotency_key'] ?? null) && $checkpoint['idempotency_key'] !== ''
            ? $checkpoint['idempotency_key']
            : (string) Str::uuid();
        $startedAt = CarbonImmutable::now();

        // Saved before the request: a worker that dies after Zernio accepted
        // the post sends the same key again and gets the same post back.
        $checkpoint = $this->remember($post, [
            'idempotency_key' => $idempotencyKey,
            'started_at' => $startedAt->toIso8601String(),
        ]);

        try {
            $response = $this->client->createPost($payload, $idempotencyKey);
        } catch (ConnectionException $e) {
            throw $this->unavailable('Zernio did not answer the publish request: '.$e->getMessage());
        }

        if ($response->failed()) {
            $this->failFromResponse($post, $response, 'create');
        }

        $zernioPost = (array) (data_get($response->json(), 'post') ?? []);
        $zernioPostId = $this->postIdOf($zernioPost);

        if ($zernioPostId === null) {
            $this->forget($post);

            throw ZernioPublishException::misconfigured('Zernio did not return a post id.');
        }

        $this->remember($post, [...$checkpoint, 'post_id' => $zernioPostId]);

        Log::info('Marmara Zernio: TikTok post created', [
            'post_id' => $post->id,
            'zernio_post_id' => $zernioPostId,
            'http_status' => $response->status(),
        ]);

        return $this->settle($post, $zernioPost, $zernioPostId, $zernioAccountId, $startedAt, $response->body());
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
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    public static function checkpoint(?array $context): array
    {
        $checkpoint = data_get($context, self::CHECKPOINT);

        return is_array($checkpoint) ? $checkpoint : [];
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     * @return array<string, mixed>
     */
    private function resume(Post $post, array $checkpoint, string $zernioAccountId): array
    {
        $zernioPostId = (string) $checkpoint['post_id'];
        $startedAt = $this->startedAt($checkpoint);

        try {
            $response = $this->client->getPost($zernioPostId);
        } catch (ConnectionException) {
            throw $this->pending($zernioPostId, null);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw $this->pending($zernioPostId, null);
        }

        if ($response->failed()) {
            $this->forget($post);

            throw ZernioPublishException::fromApiResponse($response);
        }

        $zernioPost = (array) (data_get($response->json(), 'post') ?? $response->json() ?? []);

        return $this->settle($post, $zernioPost, $zernioPostId, $zernioAccountId, $startedAt, $response->body());
    }

    /**
     * @param  array<string, mixed>  $zernioPost
     * @return array{id: string, url: ?string}
     */
    private function settle(Post $post, array $zernioPost, string $zernioPostId, string $zernioAccountId, CarbonInterface $startedAt, string $rawResponse): array
    {
        $target = $this->tiktokTarget($zernioPost, $zernioAccountId);
        $status = strtolower((string) (data_get($target, 'status') ?? data_get($zernioPost, 'status') ?? ''));

        if (in_array($status, self::PUBLISHED_STATUSES, true)) {
            return $this->result($post, $zernioPostId, $target ?? []);
        }

        if (in_array($status, self::FAILED_STATUSES, true)) {
            $this->forget($post);

            throw ZernioPublishException::fromFailedTarget($target ?? $zernioPost, $rawResponse);
        }

        Log::info('Marmara Zernio: TikTok post still processing', [
            'post_id' => $post->id,
            'zernio_post_id' => $zernioPostId,
            'status' => $status,
            'seconds_since_start' => (int) $startedAt->diffInSeconds(now()),
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

    /**
     * A refusal of the create call. An outage or a request Zernio is still
     * processing under the same Idempotency-Key is retried with that key;
     * anything else ends the attempt, so the next one starts over.
     */
    private function failFromResponse(Post $post, Response $response, string $phase): never
    {
        $code = data_get($response->json(), 'code');

        if ($response->serverError() || ($response->status() === 409 && $code === 'idempotency_conflict')) {
            throw $this->unavailable("Zernio {$phase} answered HTTP {$response->status()}.", $response);
        }

        $this->forget($post);

        throw ZernioPublishException::fromApiResponse($response);
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

    /**
     * @param  array<string, mixed>  $checkpoint
     */
    private function startedAt(array $checkpoint): CarbonImmutable
    {
        $value = $checkpoint['started_at'] ?? null;

        return is_string($value)
            ? rescue(fn (): CarbonImmutable => CarbonImmutable::parse($value), CarbonImmutable::now(), report: false)
            : CarbonImmutable::now();
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     * @return array<string, mixed>
     */
    private function remember(Post $post, array $checkpoint): array
    {
        $post->writePublication([
            'error_context' => [...($post->error_context ?? []), self::CHECKPOINT => $checkpoint],
        ]);

        return $checkpoint;
    }

    /**
     * Drops the checkpoint once the attempt is over, so a retry creates a
     * new Zernio post instead of resuming a failed one.
     */
    private function forget(Post $post): void
    {
        $context = $post->error_context ?? [];

        if (! array_key_exists(self::CHECKPOINT, $context)) {
            return;
        }

        unset($context[self::CHECKPOINT]);

        $post->writePublication(['error_context' => $context === [] ? null : $context]);
    }
}
