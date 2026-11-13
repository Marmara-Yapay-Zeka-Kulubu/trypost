<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Analytics\Collectors;

use App\Dto\Analytics\PublicationPage;
use App\Marmara\Zernio\Analytics\Concerns\ReadsZernioPublicationHistory;
use App\Marmara\Zernio\Analytics\ZernioPostAnalytics;
use App\Marmara\Zernio\ZernioConfig;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\TikTokPublicationCollector;
use Carbon\CarbonImmutable;

/**
 * TikTok publication history (discovery and backfill) of a Zernio-mapped
 * account read from Zernio, without the native `video.list` scope. Other
 * TikTok accounts keep the native collector.
 */
class ZernioTikTokPublicationCollector extends TikTokPublicationCollector
{
    use ReadsZernioPublicationHistory;

    public function __construct(
        private readonly ZernioConfig $config,
        private readonly ZernioPostAnalytics $analytics,
    ) {}

    public function page(SocialAccount $account, ?string $cursor, CarbonImmutable $cutoff): PublicationPage
    {
        $zernioAccountId = $this->config->analyticsAccountFor($account);

        if ($zernioAccountId === null) {
            return parent::page($account, $cursor, $cutoff);
        }

        return $this->zernioPage($this->analytics, $account, $zernioAccountId, $cursor, $cutoff);
    }
}
