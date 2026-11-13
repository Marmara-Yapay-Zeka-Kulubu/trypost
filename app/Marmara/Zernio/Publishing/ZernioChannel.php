<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Publishing;

use App\Enums\SocialAccount\Platform;
use App\Models\Post;

/**
 * What differs between the platforms ZernioPostFlow publishes to: the
 * payload of `POST /v1/posts` and how a published Zernio target becomes the
 * TryPost post's id and URL. Everything else (attempts, idempotency, status
 * polling, recovery) is shared.
 */
interface ZernioChannel
{
    public function platform(): Platform;

    /**
     * Validates the post (throwing ZernioPublishException) and builds the
     * create payload, media included.
     *
     * @return array<string, mixed>
     */
    public function payload(Post $post, string $zernioAccountId, string $reference): array;

    /**
     * The publish result of a target Zernio reports as published.
     *
     * @param  array<string, mixed>  $target
     * @return array{id: string, url: ?string}
     */
    public function result(Post $post, string $zernioPostId, array $target): array;
}
