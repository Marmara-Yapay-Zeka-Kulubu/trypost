<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Analytics\Collectors;

use App\Dto\Analytics\PublicationPage;
use App\Marmara\Zernio\Analytics\ZernioTikTokAnalytics;
use App\Marmara\Zernio\ZernioConfig;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\TikTokPublicationCollector;
use Carbon\CarbonImmutable;

/**
 * TikTok publication history (discovery and backfill) of a Zernio-mapped
 * account read from Zernio, without the native `video.list` scope. Other
 * TikTok accounts keep the native collector.
 *
 * The cursor is Zernio's page number over a fixed window (cutoff to today),
 * newest first. Zernio keeps about 12 months of synced posts and answers at
 * most 366 days per request, so a cutoff further back ends provider limited.
 * A Zernio post without a TikTok video id (an upload TikTok never confirmed)
 * is left out.
 */
class ZernioTikTokPublicationCollector extends TikTokPublicationCollector
{
    public function __construct(
        private readonly ZernioConfig $config,
        private readonly ZernioTikTokAnalytics $analytics,
    ) {}

    public function page(SocialAccount $account, ?string $cursor, CarbonImmutable $cutoff): PublicationPage
    {
        $zernioAccountId = $this->config->analyticsAccountFor($account);

        if ($zernioAccountId === null) {
            return parent::page($account, $cursor, $cutoff);
        }

        $oldestAvailable = CarbonImmutable::now('UTC')->subDays(ZernioTikTokAnalytics::MAX_RANGE_DAYS)->startOfDay();
        $beyondZernio = $cutoff->lessThan($oldestAvailable);
        $page = is_string($cursor) && ctype_digit($cursor) && (int) $cursor > 0 ? (int) $cursor : 1;

        $result = $this->analytics->page($account, $zernioAccountId, $cutoff->max($oldestAvailable), $page);
        $hasNext = $page < $result['pages'];

        return new PublicationPage(
            $result['publications'],
            $hasNext ? (string) ($page + 1) : null,
            ! $hasNext,
            providerLimited: ! $hasNext && $beyondZernio,
            canStopAtTarget: false,
        );
    }
}
