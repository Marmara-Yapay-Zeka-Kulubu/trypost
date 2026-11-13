<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Analytics\Collectors;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Marmara\Zernio\Analytics\ZernioPostAnalytics;
use App\Marmara\Zernio\Publishing\ZernioPostFlow;
use App\Marmara\Zernio\ZernioConfig;
use App\Models\AnalyticsPublication;
use App\Services\Analytics\Collectors\Metrics\AbstractPublicationMetricsCollector;
use Carbon\CarbonImmutable;

/**
 * LinkedIn page post metrics read from Zernio, by the post URN or, for a
 * post Zernio published without one yet, its Zernio post id.
 */
class ZernioLinkedInPagePublicationMetricsCollector extends AbstractPublicationMetricsCollector
{
    public function __construct(
        private readonly ZernioConfig $config,
        private readonly ZernioPostAnalytics $analytics,
    ) {}

    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $zernioAccountId = $this->config->analyticsAccountFor($this->account($publication))
            ?? throw AnalyticsCollectionException::unsupported('LinkedIn page metrics need a Zernio mapping');
        $postId = ZernioPostFlow::zernioPostId($publication->remote_id) ?? $publication->remote_id;

        $metrics = $this->analytics->metrics(
            Platform::LinkedInPage,
            $this->analytics->postAnalytics(Platform::LinkedInPage, $zernioAccountId, $postId),
        );

        return $this->observation($date, $this->withEngagements($metrics));
    }
}
