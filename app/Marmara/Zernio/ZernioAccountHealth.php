<?php

declare(strict_types=1);

namespace App\Marmara\Zernio;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Whether a Zernio account is usable, from `GET /v1/accounts` (cached five
 * minutes): healthy, unhealthy (Zernio reports it disconnected), missing (not
 * listed for this key), or null when Zernio cannot be asked. The publish
 * guard, the analytics account guard and the connection verifier decide a
 * mapped channel by this answer instead of its native token.
 *
 * @see https://docs.zernio.com/accounts/list-accounts
 */
class ZernioAccountHealth
{
    public const string HEALTHY = 'healthy';

    public const string UNHEALTHY = 'unhealthy';

    public const string MISSING = 'missing';

    /** How long a healthy, unhealthy or missing answer is reused. */
    private const int CACHE_SECONDS = 300;

    public function __construct(private readonly ZernioClient $client) {}

    public function status(string $zernioAccountId, string $zernioPlatform): ?string
    {
        $cached = Cache::get(self::cacheKey($zernioAccountId));

        if (is_string($cached)) {
            return $cached;
        }

        $health = $this->fetch($zernioAccountId, $zernioPlatform);

        if ($health !== null) {
            Cache::put(self::cacheKey($zernioAccountId), $health, self::CACHE_SECONDS);
        }

        return $health;
    }

    /**
     * Usable, or not known to be unusable (Zernio unreachable): the caller
     * then meets the outage itself.
     */
    public function usable(string $zernioAccountId, string $zernioPlatform): bool
    {
        return ! in_array($this->status($zernioAccountId, $zernioPlatform), [self::UNHEALTHY, self::MISSING], true);
    }

    public static function forget(string $zernioAccountId): void
    {
        Cache::forget(self::cacheKey($zernioAccountId));
    }

    private function fetch(string $zernioAccountId, string $zernioPlatform): ?string
    {
        try {
            $response = $this->client->listAccounts(['platform' => $zernioPlatform], quick: true);
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
            || data_get($account, 'needsReconnection') === true
            || data_get($account, 'disconnected') === true;

        return $unhealthy ? self::UNHEALTHY : self::HEALTHY;
    }

    private static function cacheKey(string $zernioAccountId): string
    {
        return "marmara-zernio:account-health:{$zernioAccountId}";
    }
}
