<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Analytics\Collectors;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\PublicationContentType;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Marmara\LinkedIn\Analytics\Concerns\FallsBackForOtherAccounts;
use App\Marmara\LinkedIn\Analytics\LinkedInPostMetrics;
use App\Marmara\LinkedIn\LinkedInApi;
use App\Marmara\LinkedIn\LinkedInConfig;
use App\Marmara\LinkedIn\NativeLinkedInAccounts;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Metrics\AbstractPublicationMetricsCollector;
use Carbon\CarbonImmutable;

/**
 * A LinkedIn page post's lifetime metrics from organizationalEntityShareStatistics
 * (batched and cached by LinkedInPostMetrics) and, for a video post, its
 * views from videoAnalytics. Video views are optional: a refused or empty
 * video read keeps the other metrics.
 */
class NativeLinkedInPagePublicationMetricsCollector extends AbstractPublicationMetricsCollector
{
    use FallsBackForOtherAccounts;

    public function __construct(
        private readonly NativeLinkedInAccounts $accounts,
        private readonly LinkedInPostMetrics $posts,
        private readonly LinkedInConfig $config,
    ) {}

    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        $reason = $this->accounts->reason($account);

        if ($reason !== null) {
            /** @var AbstractPublicationMetricsCollector $previous */
            $previous = $this->previous($reason);

            return $previous->collect($publication, $date);
        }

        AnalyticsCollectionException::unlessGranted($account, 'rw_organization_admin');
        $urn = (string) $publication->remote_id;

        if (LinkedInApi::urnKind($urn) === null) {
            throw AnalyticsCollectionException::unsupported('LinkedIn post metrics need the post URN (urn:li:share: or urn:li:ugcPost:)');
        }

        if ($publication->provider_published_at?->lessThan(CarbonImmutable::now('UTC')->subDays(LinkedInConfig::STATS_WINDOW_DAYS))) {
            throw AnalyticsCollectionException::unsupported('LinkedIn keeps post statistics for 12 months only');
        }

        $statistics = $this->posts->statistics($account, $urn);
        $engagement = data_get($statistics, 'engagement');

        return $this->observation(
            $date,
            $this->withEngagements($this->posts->metrics($statistics, $this->videoViews($account, $publication, $urn))),
            is_numeric($engagement) ? ['linkedin_engagement' => (float) $engagement] : null,
        );
    }

    private function videoViews(SocialAccount $account, AnalyticsPublication $publication, string $urn): ?int
    {
        $isVideo = $publication->content_type === PublicationContentType::Video
            || $publication->provider_content_type === 'video';
        $maxAge = $this->config->videoViewsMaxAgeDays();

        if (! $isVideo
            || LinkedInApi::urnKind($urn) !== 'ugcPost'
            || $maxAge === 0
            || $publication->provider_published_at?->lessThan(CarbonImmutable::now('UTC')->subDays($maxAge))
            || $account->missingScope('r_organization_social') !== null) {
            return null;
        }

        try {
            return $this->posts->videoViews($account, $urn);
        } catch (AnalyticsCollectionException $exception) {
            if (in_array($exception->category, ['rate_limited', 'transient', 'authentication'], true)) {
                throw $exception;
            }

            return null;
        }
    }
}
