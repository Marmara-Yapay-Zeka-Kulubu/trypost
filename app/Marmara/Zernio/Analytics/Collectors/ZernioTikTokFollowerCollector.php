<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Analytics\Collectors;

use App\Dto\Analytics\AccountDailyObservation;
use App\Marmara\Zernio\Analytics\ZernioTikTokAnalytics;
use App\Marmara\Zernio\ZernioConfig;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Followers\TikTokFollowerCollector;
use Carbon\CarbonImmutable;

/**
 * The daily follower count of a Zernio-mapped TikTok account read from
 * Zernio's follower stats, without the native `user.info.stats` scope.
 */
class ZernioTikTokFollowerCollector extends TikTokFollowerCollector
{
    public function __construct(
        private readonly ZernioConfig $config,
        private readonly ZernioTikTokAnalytics $analytics,
    ) {}

    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        $zernioAccountId = $this->config->analyticsAccountFor($account);

        if ($zernioAccountId === null) {
            return parent::collect($account, $date);
        }

        $followers = $this->analytics->followers($zernioAccountId, $date->subDays(7), $date);

        return $this->observation($date, $followers['current']);
    }
}
