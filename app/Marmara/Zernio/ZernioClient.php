<?php

declare(strict_types=1);

namespace App\Marmara\Zernio;

use App\Marmara\Zernio\Exceptions\ZernioPublishException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Zernio REST API (formerly Late, getlate.dev).
 * Every call answers the raw Response: the publisher decides what a status
 * means for the TryPost post.
 *
 * @see https://docs.zernio.com/quickstart
 */
class ZernioClient
{
    /**
     * Budget of a read someone is waiting on (the post editor, the publish
     * job's channel check): the same 3 s connect / 5 s total that upstream's
     * interactive TikTok calls use (HasSocialHttpClient::socialHttp()).
     */
    public const int QUICK_CONNECT_TIMEOUT_SECONDS = 3;

    public const int QUICK_TIMEOUT_SECONDS = 5;

    public function __construct(private readonly ZernioConfig $config) {}

    /**
     * @param  array<string, mixed>  $query
     * @param  bool  $quick  the short budget, for the publish guard's health check
     *
     * @see https://docs.zernio.com/accounts/list-accounts
     */
    public function listAccounts(array $query = [], bool $quick = false): Response
    {
        return ($quick ? $this->quickRequest() : $this->request())->get('/accounts', $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @see https://docs.zernio.com/posts/create-post
     */
    public function createPost(array $payload, string $idempotencyKey): Response
    {
        return $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/posts', $payload);
    }

    /**
     * @see https://docs.zernio.com/posts/get-post
     */
    public function getPost(string $postId): Response
    {
        return $this->request()->get('/posts/'.rawurlencode($postId));
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @see https://docs.zernio.com/posts/list-posts
     */
    public function listPosts(array $query): Response
    {
        return $this->request()->get('/posts', $query);
    }

    /**
     * @see https://docs.zernio.com/accounts/get-tiktok-creator-info
     */
    public function creatorInfo(string $accountId, string $mediaType): Response
    {
        return $this->quickRequest()->get('/accounts/'.rawurlencode($accountId).'/tiktok/creator-info', ['mediaType' => $mediaType]);
    }

    /**
     * Stored post analytics: one post (`postId`) or a page of posts, those
     * published through Zernio and those synced from the platform (`source`).
     *
     * @param  array<string, mixed>  $query
     *
     * @see https://docs.zernio.com/analytics/get-analytics
     */
    public function analytics(array $query): Response
    {
        return $this->request()->get('/analytics', $query);
    }

    /**
     * Daily follower counts per account (refreshed by Zernio once a day).
     *
     * @param  array<string, mixed>  $query
     *
     * @see https://docs.zernio.com/accounts/get-follower-stats
     */
    public function followerStats(array $query): Response
    {
        return $this->request()->get('/accounts/follower-stats', $query);
    }

    /**
     * @see https://docs.zernio.com/guides/media-uploads
     */
    public function presignMedia(string $filename, string $contentType, ?int $size = null): Response
    {
        return $this->request()->post('/media/presign', array_filter([
            'filename' => $filename,
            'contentType' => $contentType,
            'size' => $size,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * PUT the file to the presigned storage URL. It goes to storage, not the
     * API, so it carries no Authorization header.
     */
    public function uploadToPresignedUrl(string $uploadUrl, string $path, string $contentType): Response
    {
        $stream = Utils::streamFor(fopen($path, 'rb'));

        return Http::timeout($this->config->timeout() * 5)
            ->withBody($stream, $contentType)
            ->put($uploadUrl);
    }

    private function quickRequest(): PendingRequest
    {
        return $this->request()
            ->connectTimeout(self::QUICK_CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::QUICK_TIMEOUT_SECONDS);
    }

    private function request(): PendingRequest
    {
        $apiKey = $this->config->apiKey();

        if ($apiKey === null) {
            throw ZernioPublishException::misconfigured('ZERNIO_API_KEY is not set.');
        }

        return Http::baseUrl($this->config->baseUrl())
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->config->timeout());
    }
}
