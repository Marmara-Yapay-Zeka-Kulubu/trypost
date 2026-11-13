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
    public function __construct(private readonly ZernioConfig $config) {}

    /**
     * @param  array<string, mixed>  $query
     *
     * @see https://docs.zernio.com/accounts/list-accounts
     */
    public function listAccounts(array $query = []): Response
    {
        return $this->request()->get('/accounts', $query);
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
        return $this->request()->get('/accounts/'.rawurlencode($accountId).'/tiktok/creator-info', ['mediaType' => $mediaType]);
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
