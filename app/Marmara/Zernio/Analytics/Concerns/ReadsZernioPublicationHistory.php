<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Analytics\Concerns;

use App\Dto\Analytics\PublicationPage;
use App\Marmara\Zernio\Analytics\ZernioPostAnalytics;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

/**
 * Publication history (discovery and backfill) read from Zernio. The cursor
 * is Zernio's page number over a fixed window (cutoff to today), newest
 * first. Zernio keeps about 12 months of synced posts and answers at most
 * 366 days per request, so a cutoff further back ends provider limited. A
 * Zernio post without a platform post id (an upload the platform never
 * confirmed) is left out.
 */
trait ReadsZernioPublicationHistory
{
    private function zernioPage(ZernioPostAnalytics $analytics, SocialAccount $account, string $zernioAccountId, ?string $cursor, CarbonImmutable $cutoff): PublicationPage
    {
        $oldestAvailable = CarbonImmutable::now('UTC')->subDays(ZernioPostAnalytics::MAX_RANGE_DAYS)->startOfDay();
        $beyondZernio = $cutoff->lessThan($oldestAvailable);
        $page = is_string($cursor) && ctype_digit($cursor) && (int) $cursor > 0 ? (int) $cursor : 1;

        $result = $analytics->page($account, $zernioAccountId, $cutoff->max($oldestAvailable), $page);
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
