<?php

declare(strict_types=1);

namespace App\Marmara\Zernio;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;

/**
 * Reads config/marmara-zernio.php. A TryPost TikTok account publishes through
 * Zernio only when the module is enabled and the account is mapped.
 */
class ZernioConfig
{
    public const string MEDIA_MODE_UPLOAD = 'upload';

    public const string MEDIA_MODE_URL = 'url';

    public function enabled(): bool
    {
        return (bool) config('marmara-zernio.enabled', false);
    }

    public function apiKey(): ?string
    {
        $key = config('marmara-zernio.api_key');

        return is_string($key) && filled($key) ? trim($key) : null;
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('marmara-zernio.base_url', 'https://zernio.com/api/v1'), '/');
    }

    public function timeout(): int
    {
        return max(10, (int) config('marmara-zernio.timeout', 120));
    }

    public function mediaMode(): string
    {
        return config('marmara-zernio.tiktok.media_mode') === self::MEDIA_MODE_URL
            ? self::MEDIA_MODE_URL
            : self::MEDIA_MODE_UPLOAD;
    }

    public function statusPollSeconds(): int
    {
        return max(15, (int) config('marmara-zernio.tiktok.status_poll_seconds', 60));
    }

    /**
     * How many status checks fit in the window, after the one the publish runs.
     */
    public function statusMaxChecks(): int
    {
        $windowSeconds = max(1, (int) config('marmara-zernio.tiktok.status_window_minutes', 180)) * 60;

        return max(1, intdiv($windowSeconds, $this->statusPollSeconds()));
    }

    /**
     * Whether analytics of the mapped TikTok accounts come from Zernio.
     */
    public function analyticsEnabled(): bool
    {
        return $this->enabled() && (bool) config('marmara-zernio.analytics.enabled', true);
    }

    /**
     * Posts per analytics list request (Zernio allows 1 to 100).
     */
    public function analyticsPageSize(): int
    {
        return min(100, max(10, (int) config('marmara-zernio.analytics.page_size', 50)));
    }

    /**
     * How long a post's metrics read from a history page are reused by the
     * metrics read, instead of a request per post.
     */
    public function analyticsCacheMinutes(): int
    {
        return max(0, (int) config('marmara-zernio.analytics.cache_minutes', 360));
    }

    public function analyticsImportLimit(): int
    {
        return max(0, (int) config('marmara-zernio.analytics.import_limit', 500));
    }

    /**
     * The Zernio account a TryPost TikTok account's analytics come from, or
     * null when its analytics stay native.
     */
    public function analyticsAccountFor(?SocialAccount $account): ?string
    {
        if (! $this->analyticsEnabled() || $account?->platform !== Platform::TikTok) {
            return null;
        }

        return $this->zernioAccountFor($account);
    }

    /**
     * @return array<string, string>
     */
    public function tiktokAccounts(): array
    {
        $accounts = config('marmara-zernio.tiktok.accounts', []);

        if (! is_array($accounts)) {
            return [];
        }

        return array_filter(
            array_map(fn (mixed $value): string => is_string($value) ? trim($value) : '', $accounts),
            fn (string $value, mixed $key): bool => $value !== '' && is_string($key) && trim($key) !== '',
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The Zernio account id a TryPost TikTok account publishes through, or
     * null when it keeps publishing natively.
     */
    public function zernioAccountFor(?SocialAccount $account): ?string
    {
        if (! $this->enabled() || $account === null) {
            return null;
        }

        $candidates = array_filter([
            (string) $account->id,
            (string) $account->platform_user_id,
            ltrim((string) $account->username, '@'),
        ], fn (string $value): bool => $value !== '');

        foreach ($this->tiktokAccounts() as $key => $zernioAccountId) {
            $key = ltrim(trim($key), '@');

            foreach ($candidates as $candidate) {
                if (strcasecmp($key, $candidate) === 0) {
                    return $zernioAccountId;
                }
            }
        }

        return null;
    }

    /**
     * What is wrong with the configuration, as lines an operator can act on.
     * Empty when the module is disabled or ready.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $problems = [];
        $key = $this->apiKey();

        if ($key === null) {
            $problems[] = 'ZERNIO_API_KEY is empty.';
        } elseif (! str_starts_with($key, 'sk_')) {
            $problems[] = 'ZERNIO_API_KEY does not look like a Zernio key (it starts with sk_).';
        }

        if (! str_starts_with($this->baseUrl(), 'https://')) {
            $problems[] = 'ZERNIO_BASE_URL must be an https:// URL.';
        }

        if ($this->tiktokAccounts() === []) {
            $problems[] = 'No TikTok account is mapped: set ZERNIO_TIKTOK_TRYPOST_ACCOUNT + ZERNIO_TIKTOK_ACCOUNT_ID, or ZERNIO_TIKTOK_ACCOUNTS.';
        }

        foreach ($this->tiktokAccounts() as $trypostAccount => $zernioAccountId) {
            if (preg_match('/^[a-f0-9]{24}$/i', $zernioAccountId) !== 1) {
                $problems[] = "Zernio account id for {$trypostAccount} should be a 24-character id, got {$zernioAccountId}.";
            }
        }

        return $problems;
    }
}
