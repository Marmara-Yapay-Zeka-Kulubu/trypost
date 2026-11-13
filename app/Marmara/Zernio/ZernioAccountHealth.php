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
        return $this->lookup($zernioAccountId, $zernioPlatform)['health'];
    }

    /**
     * The account as Zernio lists it (cached with its health), or null when
     * it is missing or Zernio cannot be asked.
     *
     * @return array<string, mixed>|null
     */
    public function account(string $zernioAccountId, string $zernioPlatform): ?array
    {
        return $this->lookup($zernioAccountId, $zernioPlatform)['account'];
    }

    /**
     * Whether a Zernio LinkedIn account posts as an organization (company
     * page) rather than a member's personal profile: true, false, or null
     * when the listing does not say. Read from `accountType` (the field
     * Zernio's LinkedIn connect and switch endpoints return), its metadata
     * copy, `metadata.organizationInfo` (organization accounts only), an
     * `urn:li:organization:` id, or the profile URL (`/company/` vs `/in/`).
     *
     * @see https://docs.zernio.com/platforms/linkedin
     *
     * @param  array<string, mixed>  $account
     */
    public static function isLinkedInOrganization(array $account): ?bool
    {
        foreach ([data_get($account, 'accountType'), data_get($account, 'metadata.accountType')] as $type) {
            if (is_string($type) && in_array(strtolower($type), ['organization', 'personal'], true)) {
                return strtolower($type) === 'organization';
            }
        }

        if (filled(data_get($account, 'metadata.organizationInfo'))) {
            return true;
        }

        foreach ([data_get($account, 'platformUserId'), data_get($account, 'metadata.organizationUrn')] as $id) {
            if (is_string($id) && str_starts_with($id, 'urn:li:organization:')) {
                return true;
            }

            if (is_string($id) && str_starts_with($id, 'urn:li:person:')) {
                return false;
            }
        }

        $profileUrl = (string) data_get($account, 'profileUrl', '');

        return match (true) {
            str_contains($profileUrl, 'linkedin.com/company/') => true,
            str_contains($profileUrl, 'linkedin.com/in/') => false,
            default => null,
        };
    }

    /**
     * @return array{health: ?string, account: array<string, mixed>|null}
     */
    private function lookup(string $zernioAccountId, string $zernioPlatform): array
    {
        $cached = Cache::get(self::cacheKey($zernioAccountId));

        if (is_array($cached) && array_key_exists('health', $cached)) {
            return $cached;
        }

        $found = $this->fetch($zernioAccountId, $zernioPlatform);

        if ($found['health'] !== null) {
            Cache::put(self::cacheKey($zernioAccountId), $found, self::CACHE_SECONDS);
        }

        return $found;
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

    /**
     * @return array{health: ?string, account: array<string, mixed>|null}
     */
    private function fetch(string $zernioAccountId, string $zernioPlatform): array
    {
        try {
            $response = $this->client->listAccounts(['platform' => $zernioPlatform], quick: true);
        } catch (Throwable) {
            return ['health' => null, 'account' => null];
        }

        if ($response->failed()) {
            return ['health' => null, 'account' => null];
        }

        $account = collect((array) data_get($response->json(), 'accounts', []))
            ->first(fn (mixed $account): bool => is_array($account)
                && (string) (data_get($account, '_id') ?? data_get($account, 'id')) === $zernioAccountId);

        if (! is_array($account)) {
            return ['health' => self::MISSING, 'account' => null];
        }

        $status = strtolower((string) data_get($account, 'status', ''));

        $unhealthy = in_array($status, ['disconnected', 'expired', 'error', 'inactive'], true)
            || data_get($account, 'isActive') === false
            || data_get($account, 'needsReconnect') === true
            || data_get($account, 'needsReconnection') === true
            || data_get($account, 'disconnected') === true;

        return ['health' => $unhealthy ? self::UNHEALTHY : self::HEALTHY, 'account' => $account];
    }

    private static function cacheKey(string $zernioAccountId): string
    {
        return "marmara-zernio:account-health:{$zernioAccountId}";
    }
}
