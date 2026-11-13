<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Publishing;

use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Marmara\Zernio\Exceptions\ZernioPublishException;
use App\Marmara\Zernio\Models\ZernioPublication;
use App\Marmara\Zernio\ZernioClient;
use App\Marmara\Zernio\ZernioConfig;
use App\Models\Post;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Publishes one TryPost post through Zernio, for any ZernioChannel: one
 * attempt row per post (marmara_zernio_publications), an Idempotency-Key per
 * attempt, a reference tag to find a create whose answer was lost, and the
 * status of an existing Zernio post read instead of a new create.
 *
 * It answers PublishToSocialPlatform like a native publisher: an `id`/`url`
 * array on success, PlatformUnavailableException while the platform is still
 * processing (the job checks again later), a SocialPublishException when the
 * post failed. TryPost's scheduler decides when a post goes out, so every
 * Zernio post is created with `publishNow`.
 */
class ZernioPostFlow
{
    /**
     * A create request whose answer was lost is sent again only this long
     * after the first one: Zernio keeps an Idempotency-Key for 24 hours.
     */
    private const int IDEMPOTENCY_SAFE_HOURS = 23;

    public const string POST_ID_PREFIX = 'zernio:';

    /** @var list<string> */
    private const array PUBLISHED_STATUSES = ['published'];

    /** @var list<string> */
    private const array FAILED_STATUSES = ['failed', 'cancelled', 'canceled', 'error'];

    public function __construct(
        private readonly ZernioConfig $config,
        private readonly ZernioClient $client,
    ) {}

    /**
     * @return array{id: string, url: ?string}
     */
    public function publish(Post $post, string $zernioAccountId, ZernioChannel $channel): array
    {
        $attempt = ZernioPublication::query()->where('post_id', $post->id)->first();

        if ($attempt !== null && ! $attempt->isDefinitivelyFailed()) {
            return match (true) {
                $attempt->last_status === ZernioPublication::STATUS_NEEDS_MANUAL_CHECK => throw $this->needsManualCheck($attempt, $channel),
                $attempt->zernio_post_id !== null => $this->resume($post, $attempt, $zernioAccountId, $channel),
                $attempt->last_status === ZernioPublication::STATUS_UNKNOWN
                    && $attempt->first_attempt_at->lte(now()->subHours(self::IDEMPOTENCY_SAFE_HOURS)) => $this->recoverLostCreate($post, $attempt, $zernioAccountId, $channel),
                default => $this->create($post, $attempt, $zernioAccountId, $channel),
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

        return $this->create($post, $attempt, $zernioAccountId, $channel);
    }

    /**
     * The target of a Zernio post for one platform: the one for our account,
     * else the first of that platform. `accountId` may come back as an id or
     * as an object.
     *
     * @param  array<string, mixed>  $zernioPost
     * @return array<string, mixed>|null
     */
    public static function target(array $zernioPost, string $zernioPlatform, ?string $zernioAccountId): ?array
    {
        $targets = collect((array) data_get($zernioPost, 'platforms', []))
            ->filter(fn (mixed $target): bool => is_array($target) && strtolower((string) data_get($target, 'platform')) === $zernioPlatform)
            ->values();

        $own = $targets->first(function (array $target) use ($zernioAccountId): bool {
            $accountId = data_get($target, 'accountId');
            $accountId = is_array($accountId) ? (data_get($accountId, '_id') ?? data_get($accountId, 'id')) : $accountId;

            return $zernioAccountId !== null && (string) $accountId === $zernioAccountId;
        });

        return $own ?? $targets->first();
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
     * Sends the create request of the attempt. A request already sent whose
     * answer was lost is sent again with the same Idempotency-Key, so Zernio
     * answers with the post it created instead of a second one.
     *
     * @return array{id: string, url: ?string}
     */
    private function create(Post $post, ZernioPublication $attempt, string $zernioAccountId, ZernioChannel $channel): array
    {
        $payload = $channel->payload($post, $zernioAccountId, $attempt->reference());

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

            throw ZernioMediaHost::unavailable('Zernio did not answer the publish request: '.$e->getMessage());
        }

        if ($response->failed()) {
            if ($response->serverError() || ($response->status() === 409 && data_get($response->json(), 'code') === 'idempotency_conflict')) {
                $attempt->update(['last_error' => "HTTP {$response->status()}"]);

                throw ZernioMediaHost::unavailable("Zernio create answered HTTP {$response->status()}.", $response);
            }

            $exception = ZernioPublishException::fromApiResponse($response, $channel->platform()->value);
            $attempt->update(['last_status' => ZernioPublication::STATUS_REJECTED, 'last_error' => $exception->userMessage]);

            throw $exception;
        }

        $zernioPost = (array) (data_get($response->json(), 'post') ?? []);
        $zernioPostId = $this->postIdOf($zernioPost);

        if ($zernioPostId === null) {
            $attempt->update(['last_status' => ZernioPublication::STATUS_NEEDS_MANUAL_CHECK, 'last_error' => 'Zernio answered without a post id.']);

            throw $this->needsManualCheck($attempt, $channel);
        }

        $attempt->update(['zernio_post_id' => $zernioPostId, 'last_status' => ZernioPublication::STATUS_PROCESSING]);

        Log::info('Marmara Zernio: post created', [
            'platform' => $channel->platform()->value,
            'post_id' => $post->id,
            'zernio_post_id' => $zernioPostId,
            'http_status' => $response->status(),
        ]);

        return $this->settle($post, $attempt, $zernioPost, $zernioAccountId, $response->body(), $channel);
    }

    /**
     * The create request of an attempt older than Zernio's idempotency window
     * got no answer: it is never sent again. The post is looked up by the
     * reference the request carried; when it cannot be found, an operator
     * decides (`marmara:zernio:release`).
     *
     * @return array{id: string, url: ?string}
     */
    private function recoverLostCreate(Post $post, ZernioPublication $attempt, string $zernioAccountId, ZernioChannel $channel): array
    {
        $found = $this->findByReference($attempt, $zernioAccountId, $channel);

        if ($found === null) {
            $attempt->update([
                'last_status' => ZernioPublication::STATUS_NEEDS_MANUAL_CHECK,
                'last_attempt_at' => now(),
                'last_error' => 'The create request got no answer and Zernio has no post with its reference.',
            ]);

            throw $this->needsManualCheck($attempt, $channel);
        }

        $zernioPostId = (string) $this->postIdOf($found);
        $attempt->update(['zernio_post_id' => $zernioPostId, 'last_status' => ZernioPublication::STATUS_PROCESSING, 'last_attempt_at' => now()]);

        return $this->settle($post, $attempt, $found, $zernioAccountId, (string) json_encode($found), $channel);
    }

    /**
     * The Zernio post carrying the attempt's reference (tag, or metadata as a
     * fallback), or null when Zernio has none or cannot tell.
     *
     * @return array<string, mixed>|null
     */
    private function findByReference(ZernioPublication $attempt, string $zernioAccountId, ZernioChannel $channel): ?array
    {
        $reference = $attempt->reference();
        $queries = [
            ['tags' => $reference, 'accountId' => $zernioAccountId, 'limit' => 50],
            [
                'accountId' => $zernioAccountId,
                'platform' => ZernioConfig::zernioPlatform($channel->platform()),
                'updatedSince' => $attempt->first_attempt_at->subMinutes(10)->toIso8601ZuluString(),
                'limit' => 100,
            ],
        ];

        foreach ($queries as $query) {
            try {
                $response = $this->client->listPosts($query);
            } catch (ConnectionException $e) {
                throw ZernioMediaHost::unavailable('Zernio did not answer the post lookup: '.$e->getMessage());
            }

            if ($response->serverError() || $response->status() === 429) {
                throw ZernioMediaHost::unavailable("Zernio post lookup answered HTTP {$response->status()}.", $response);
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

    private function needsManualCheck(ZernioPublication $attempt, ZernioChannel $channel): ZernioPublishException
    {
        $platform = $channel->platform()->value;

        return ZernioPublishException::invalid(
            ZernioPublishException::prefix($platform).'the publish request sent '.$attempt->first_attempt_at->toIso8601String()
            .' got no answer and is past Zernio\'s 24-hour duplicate protection, so it was not sent again.'
            .' Needs a manual check in Zernio (reference '.$attempt->reference().'). To send it anyway, run'
            .' "php artisan marmara:zernio:release '.$attempt->post_id.'" and retry the post.',
            ErrorCategory::Unknown,
            $platform,
        );
    }

    /**
     * The attempt already has a Zernio post: its status decides, nothing is
     * created again (a posts:retry included).
     *
     * @return array{id: string, url: ?string}
     */
    private function resume(Post $post, ZernioPublication $attempt, string $zernioAccountId, ZernioChannel $channel): array
    {
        $zernioPostId = (string) $attempt->zernio_post_id;
        $platform = $channel->platform()->value;

        try {
            $response = $this->client->getPost($zernioPostId);
        } catch (ConnectionException) {
            throw $this->pending($zernioPostId, null, $channel);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw $this->pending($zernioPostId, null, $channel);
        }

        if ($response->status() === 404) {
            $attempt->update([
                'last_status' => ZernioPublication::STATUS_FAILED,
                'last_attempt_at' => now(),
                'last_error' => 'Zernio no longer has this post.',
            ]);

            throw ZernioPublishException::invalid(ZernioPublishException::prefix($platform)."Zernio no longer has post {$zernioPostId}. Retry the post to send it again.", ErrorCategory::Unknown, $platform);
        }

        if ($response->failed()) {
            throw ZernioPublishException::fromApiResponse($response, $platform);
        }

        $zernioPost = (array) (data_get($response->json(), 'post') ?? $response->json() ?? []);

        return $this->settle($post, $attempt, $zernioPost, $zernioAccountId, $response->body(), $channel);
    }

    /**
     * @param  array<string, mixed>  $zernioPost
     * @return array{id: string, url: ?string}
     */
    private function settle(Post $post, ZernioPublication $attempt, array $zernioPost, string $zernioAccountId, string $rawResponse, ZernioChannel $channel): array
    {
        $zernioPostId = (string) $attempt->zernio_post_id;
        $target = self::target($zernioPost, ZernioConfig::zernioPlatform($channel->platform()), $zernioAccountId);
        $status = strtolower((string) (data_get($target, 'status') ?? data_get($zernioPost, 'status') ?? ''));

        if (in_array($status, self::PUBLISHED_STATUSES, true)) {
            $attempt->update(['last_status' => ZernioPublication::STATUS_PUBLISHED, 'last_attempt_at' => now(), 'last_error' => null]);

            return $channel->result($post, $zernioPostId, $target ?? []);
        }

        if (in_array($status, self::FAILED_STATUSES, true)) {
            $exception = ZernioPublishException::fromFailedTarget($target ?? $zernioPost, $rawResponse, $channel->platform()->value);
            $attempt->update(['last_status' => ZernioPublication::STATUS_FAILED, 'last_attempt_at' => now(), 'last_error' => $exception->userMessage]);

            throw $exception;
        }

        $attempt->update(['last_status' => ZernioPublication::STATUS_PROCESSING, 'last_attempt_at' => now()]);

        Log::info('Marmara Zernio: post still processing', [
            'platform' => $channel->platform()->value,
            'post_id' => $post->id,
            'zernio_post_id' => $zernioPostId,
            'status' => $status,
            'seconds_since_first_attempt' => (int) $attempt->first_attempt_at->diffInSeconds(now()),
        ]);

        throw $this->pending($zernioPostId, $status !== '' ? $status : null, $channel);
    }

    /**
     * @param  array<string, mixed>  $zernioPost
     */
    private function postIdOf(array $zernioPost): ?string
    {
        $id = data_get($zernioPost, '_id') ?? data_get($zernioPost, 'id');

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    private function pending(string $zernioPostId, ?string $status, ZernioChannel $channel): PlatformUnavailableException
    {
        return new PlatformUnavailableException(
            message: "Zernio is still publishing post {$zernioPostId} to {$channel->platform()->label()}",
            context: array_filter(['zernio_status' => $status]),
            retryDelaySeconds: $this->config->statusPollSeconds(),
            maxRetries: $this->config->statusMaxChecks(),
        );
    }
}
