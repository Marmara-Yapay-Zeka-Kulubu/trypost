<?php

declare(strict_types=1);

namespace App\Marmara\Zernio;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;

/**
 * Reads config/marmara-zernio.php. A TryPost account publishes (and, with
 * analytics on, is measured) through Zernio only when the module is enabled
 * and the account is in its platform's mapping.
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
     * The Zernio account a mapped TryPost account's analytics come from, or
     * null when its analytics stay with TryPost's own collectors.
     */
    public function analyticsAccountFor(?SocialAccount $account): ?string
    {
        return $this->analyticsEnabled() ? $this->zernioAccountFor($account) : null;
    }

    /**
     * The TryPost platforms this module can route through Zernio.
     *
     * @return list<Platform>
     */
    public static function supportedPlatforms(): array
    {
        return [Platform::TikTok, Platform::LinkedInPage];
    }

    /**
     * Zernio's name for a TryPost platform (its `platform` field and filter).
     */
    public static function zernioPlatform(Platform $platform): string
    {
        return match ($platform) {
            Platform::LinkedIn, Platform::LinkedInPage => 'linkedin',
            default => $platform->value,
        };
    }

    /**
     * TryPost account => Zernio account id of one platform: the
     * `ZERNIO_ACCOUNTS` entries for it and, for TikTok, the older
     * `ZERNIO_TIKTOK_*` variables. A platform without entries publishes and
     * collects natively.
     *
     * @return array<string, string>
     */
    public function accounts(Platform $platform): array
    {
        $accounts = (array) config('marmara-zernio.accounts.'.$platform->value, []);

        if ($platform === Platform::TikTok) {
            $accounts = [...$accounts, ...(array) config('marmara-zernio.tiktok.accounts', [])];
        }

        return array_filter(
            array_map(fn (mixed $value): string => is_string($value) ? trim($value) : '', $accounts),
            fn (string $value, mixed $key): bool => $value !== '' && is_string($key) && trim($key) !== '',
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * `ZERNIO_ACCOUNTS`: comma-separated `platform:trypostAccount=zernioId`
     * entries, grouped by platform (lower case). Malformed entries are skipped.
     *
     * @return array<string, array<string, string>>
     */
    public static function parseAccounts(string $value): array
    {
        $mapped = [];

        foreach (explode(',', $value) as $entry) {
            [$platform, $pair] = array_pad(array_map('trim', explode(':', $entry, 2)), 2, '');

            foreach (self::parsePairs($pair) as $trypostAccount => $zernioAccount) {
                if ($platform !== '') {
                    $mapped[strtolower($platform)][$trypostAccount] = $zernioAccount;
                }
            }
        }

        return $mapped;
    }

    /**
     * Comma-separated `trypostAccount=zernioId` pairs (`ZERNIO_TIKTOK_ACCOUNTS`).
     *
     * @return array<string, string>
     */
    public static function parsePairs(string $value): array
    {
        $pairs = [];

        foreach (explode(',', $value) as $pair) {
            [$trypostAccount, $zernioAccount] = array_pad(array_map('trim', explode('=', $pair, 2)), 2, '');

            if ($trypostAccount !== '' && $zernioAccount !== '') {
                $pairs[$trypostAccount] = $zernioAccount;
            }
        }

        return $pairs;
    }

    /**
     * Every mapping, by TryPost platform value, including entries for a
     * platform the module does not support (reported by problems()).
     *
     * @return array<string, array<string, string>>
     */
    public function allAccounts(): array
    {
        $all = [];

        foreach (array_keys((array) config('marmara-zernio.accounts', [])) as $platform) {
            $resolved = Platform::tryFrom((string) $platform);
            $all[(string) $platform] = $resolved ? $this->accounts($resolved) : array_filter((array) config("marmara-zernio.accounts.{$platform}", []), 'is_string');
        }

        $all[Platform::TikTok->value] = $this->accounts(Platform::TikTok);

        return array_filter($all);
    }

    public function hasAccounts(Platform $platform): bool
    {
        return $this->enabled() && in_array($platform, self::supportedPlatforms(), true) && $this->accounts($platform) !== [];
    }

    /**
     * @return array<string, string>
     */
    public function tiktokAccounts(): array
    {
        return $this->accounts(Platform::TikTok);
    }

    /**
     * The Zernio account a TryPost account publishes through, or null when
     * it keeps TryPost's native path: the module is off, the platform is not
     * one the module supports, or the account is not in that platform's
     * mapping. Removing a mapping moves the channel back to the native path.
     */
    public function zernioAccountFor(?SocialAccount $account): ?string
    {
        if (! $this->enabled() || $account === null || ! in_array($account->platform, self::supportedPlatforms(), true)) {
            return null;
        }

        $candidates = array_filter([
            (string) $account->id,
            (string) $account->platform_user_id,
            ltrim((string) $account->username, '@'),
        ], fn (string $value): bool => $value !== '');

        foreach ($this->accounts($account->platform) as $key => $zernioAccountId) {
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

        $all = $this->allAccounts();

        if ($all === []) {
            $problems[] = 'No account is mapped: set ZERNIO_ACCOUNTS (or ZERNIO_TIKTOK_TRYPOST_ACCOUNT + ZERNIO_TIKTOK_ACCOUNT_ID).';
        }

        $supported = array_map(fn (Platform $platform): string => $platform->value, self::supportedPlatforms());

        foreach ($all as $platform => $accounts) {
            if (! in_array($platform, $supported, true)) {
                $problems[] = "ZERNIO_ACCOUNTS: {$platform} is not supported (use ".implode(', ', $supported).').';

                continue;
            }

            foreach ($accounts as $trypostAccount => $zernioAccountId) {
                if (preg_match('/^[a-f0-9]{24}$/i', $zernioAccountId) !== 1) {
                    $problems[] = "Zernio account id for {$platform}:{$trypostAccount} should be a 24-character id, got {$zernioAccountId}.";
                }
            }
        }

        return $problems;
    }
}
