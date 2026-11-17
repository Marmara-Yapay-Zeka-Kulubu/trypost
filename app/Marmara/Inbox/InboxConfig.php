<?php

declare(strict_types=1);

namespace App\Marmara\Inbox;

use App\Enums\SocialAccount\Platform;

/**
 * Reads config/marmara-inbox.php with sane bounds. Every flag is read on each
 * call, never cached at boot, so tests and `config:cache` behave the same.
 */
class InboxConfig
{
    public const array PROVIDERS = ['linkedin', 'instagram', 'facebook', 'zernio', 'x'];

    public function enabled(): bool
    {
        return (bool) config('marmara-inbox.enabled', false);
    }

    public function providerEnabled(string $provider): bool
    {
        return $this->enabled() && (bool) config("marmara-inbox.providers.{$provider}", false);
    }

    /**
     * Scopes TryPost's connect adds for a platform while its provider is on.
     *
     * @return list<string>
     */
    public function extraScopes(Platform $platform): array
    {
        $provider = match ($platform) {
            Platform::Instagram, Platform::InstagramFacebook => 'instagram',
            Platform::Facebook => 'facebook',
            Platform::X => 'x',
            default => null,
        };

        if ($provider === null || ! $this->providerEnabled($provider)) {
            return [];
        }

        return array_values(array_filter(
            (array) config("marmara-inbox.scopes.{$platform->value}", []),
            fn (mixed $scope): bool => is_string($scope) && $scope !== '',
        ));
    }

    public function notifyMaxAgeHours(): int
    {
        return max(0, (int) config('marmara-inbox.notify_max_age_hours', 24));
    }

    public function maxPagesPerSync(): int
    {
        return min(20, max(1, (int) config('marmara-inbox.max_pages_per_sync', 3)));
    }

    public function postWindowDays(): int
    {
        return min(90, max(1, (int) config('marmara-inbox.post_window_days', 14)));
    }

    public function intervalMinutes(string $provider): int
    {
        $key = match ($provider) {
            'instagram', 'facebook' => 'meta',
            default => $provider,
        };

        return max(1, (int) config("marmara-inbox.{$key}.interval_minutes", 10));
    }

    public function linkedInDailyCalls(): int
    {
        return max(0, (int) config('marmara-inbox.linkedin.daily_calls', 30));
    }

    public function postsPerRun(string $provider): int
    {
        $key = match ($provider) {
            'instagram', 'facebook' => 'meta',
            default => $provider,
        };

        return min(25, max(1, (int) config("marmara-inbox.{$key}.posts_per_run", 3)));
    }

    public function metaBackoffMinutes(): int
    {
        return max(1, (int) config('marmara-inbox.meta.backoff_minutes', 30));
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function zernioAccounts(): array
    {
        return array_filter((array) config('marmara-inbox.zernio.accounts', []), 'is_array');
    }

    public function zernioWebhookSecret(): ?string
    {
        $secret = config('marmara-inbox.zernio.webhook_secret');

        return is_string($secret) && trim($secret) !== '' ? trim($secret) : null;
    }
}
