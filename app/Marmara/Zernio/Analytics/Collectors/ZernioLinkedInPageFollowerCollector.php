<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Analytics\Collectors;

use App\Dto\Analytics\AccountDailyObservation;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Marmara\Zernio\Analytics\ZernioPostAnalytics;
use App\Marmara\Zernio\ZernioConfig;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Followers\AbstractFollowerCollector;
use Carbon\CarbonImmutable;

/**
 * A LinkedIn page's daily follower count from Zernio's follower stats.
 */
class ZernioLinkedInPageFollowerCollector extends AbstractFollowerCollector
{
    public function __construct(
        private readonly ZernioConfig $config,
        private readonly ZernioPostAnalytics $analytics,
    ) {}

    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        $zernioAccountId = $this->config->analyticsAccountFor($account)
            ?? throw AnalyticsCollectionException::unsupported('LinkedIn page followers need a Zernio mapping');

        return $this->observation($date, $this->analytics->followers($zernioAccountId, $date->subDays(7), $date)['current']);
    }
}
