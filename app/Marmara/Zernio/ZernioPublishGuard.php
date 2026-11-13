<?php

declare(strict_types=1);

namespace App\Marmara\Zernio;

use App\Exceptions\Social\ErrorCategory;
use App\Marmara\Hooks\PublishGuardVerdict;
use App\Marmara\Hooks\SocialAccountPublishGuard;
use App\Models\Post;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A TikTok channel mapped to Zernio is checked by Zernio's account health,
 * not by the native TikTok token TryPost holds for it: the native token may
 * expire or lose its scopes without affecting the Zernio publish.
 */
class ZernioPublishGuard extends SocialAccountPublishGuard
{
    /** How long a healthy or missing Zernio account answer is reused. */
    private const int CACHE_SECONDS = 300;

    private const string HEALTHY = 'healthy';

    private const string UNHEALTHY = 'unhealthy';

    private const string MISSING = 'missing';

    public function __construct(
        private readonly ZernioConfig $config,
        private readonly ZernioClient $client,
    ) {}

    public function check(Post $post): PublishGuardVerdict
    {
        $zernioAccountId = $this->config->zernioAccountFor($post->socialAccount);

        if ($zernioAccountId === null) {
            return parent::check($post);
        }

        return match ($this->health($zernioAccountId)) {
            self::MISSING => PublishGuardVerdict::failed(
                "TikTok (via Zernio): the Zernio account {$zernioAccountId} is not connected. Reconnect it in Zernio.",
                $this->failureContext('zernio_account_missing'),
            ),
            self::UNHEALTHY => PublishGuardVerdict::failed(
                "TikTok (via Zernio): the Zernio account {$zernioAccountId} needs to be reconnected in Zernio.",
                $this->failureContext('zernio_account_disconnected'),
            ),
            // Unknown (Zernio unreachable, key refused): the publisher reports it.
            default => PublishGuardVerdict::passed(),
        };
    }

    /**
     * A Zernio-mapped channel's video id comes from Zernio, so its native
     * connection does not matter.
     */
    public function requiresConnectedChannel(Post $post): bool
    {
        return $this->config->zernioAccountFor($post->socialAccount) === null
            && parent::requiresConnectedChannel($post);
    }

    public static function forget(string $zernioAccountId): void
    {
        Cache::forget(self::cacheKey($zernioAccountId));
    }

    private function health(string $zernioAccountId): ?string
    {
        $cached = Cache::get(self::cacheKey($zernioAccountId));

        if (is_string($cached)) {
            return $cached;
        }

        $health = $this->fetchHealth($zernioAccountId);

        if ($health !== null) {
            Cache::put(self::cacheKey($zernioAccountId), $health, self::CACHE_SECONDS);
        }

        return $health;
    }

    private function fetchHealth(string $zernioAccountId): ?string
    {
        try {
            $response = $this->client->listAccounts(['platform' => 'tiktok']);
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $account = collect((array) data_get($response->json(), 'accounts', []))
            ->first(fn (mixed $account): bool => is_array($account)
                && (string) (data_get($account, '_id') ?? data_get($account, 'id')) === $zernioAccountId);

        if ($account === null) {
            return self::MISSING;
        }

        $status = strtolower((string) data_get($account, 'status', ''));

        $unhealthy = in_array($status, ['disconnected', 'expired', 'error', 'inactive'], true)
            || data_get($account, 'isActive') === false
            || data_get($account, 'needsReconnect') === true
            || data_get($account, 'disconnected') === true;

        return $unhealthy ? self::UNHEALTHY : self::HEALTHY;
    }

    /**
     * @return array<string, mixed>
     */
    private function failureContext(string $reason): array
    {
        return [
            'category' => ErrorCategory::Permission->value,
            'reason' => $reason,
            'failed_at' => now()->toIso8601String(),
        ];
    }

    private static function cacheKey(string $zernioAccountId): string
    {
        return "marmara-zernio:account-health:{$zernioAccountId}";
    }
}
