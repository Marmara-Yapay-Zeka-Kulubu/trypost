<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Analytics;

use App\Enums\SocialAccount\Status;
use App\Marmara\Hooks\AnalyticsAccountGuard;
use App\Marmara\Zernio\ZernioAccountHealth;
use App\Marmara\Zernio\ZernioConfig;
use App\Models\SocialAccount;

/**
 * A Zernio-measured channel stays in analytics while its Zernio account is
 * usable, whatever its native token says (expired, disconnected). A Zernio
 * account reported disconnected or missing takes it out, like a lost native
 * connection does for other channels.
 */
class ZernioAnalyticsAccountGuard extends AnalyticsAccountGuard
{
    public function __construct(
        private readonly ZernioConfig $config,
        private readonly ZernioAccountHealth $health,
    ) {}

    public function alsoUsableAccountIds(): array
    {
        if (! $this->config->analyticsEnabled()) {
            return [];
        }

        return SocialAccount::query()
            ->whereIn('platform', ZernioConfig::supportedPlatforms())
            ->where('status', '!=', Status::Connected)
            ->get(['id', 'platform', 'platform_user_id', 'username'])
            ->filter(function (SocialAccount $account): bool {
                $zernioAccountId = $this->config->analyticsAccountFor($account);

                return $zernioAccountId !== null
                    && $this->health->usable($zernioAccountId, ZernioConfig::zernioPlatform($account->platform));
            })
            ->map(fn (SocialAccount $account): string => (string) $account->id)
            ->values()
            ->all();
    }
}
