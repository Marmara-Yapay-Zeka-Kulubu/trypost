<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Analytics\Collectors;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Marmara\Zernio\Analytics\ZernioPostAnalytics;
use App\Marmara\Zernio\ZernioConfig;
use App\Marmara\Zernio\ZernioTikTokPublisher;
use App\Models\AnalyticsPublication;
use App\Services\Analytics\Collectors\Metrics\TikTokPublicationMetricsCollector;
use App\Services\Social\TikTokPublisher;
use Carbon\CarbonImmutable;

/**
 * TikTok post metrics of a Zernio-mapped account read from Zernio, without
 * the native `video.list` scope. Other TikTok accounts keep the native one.
 */
class ZernioTikTokPublicationMetricsCollector extends TikTokPublicationMetricsCollector
{
    public function __construct(
        private readonly ZernioConfig $config,
        private readonly ZernioPostAnalytics $analytics,
    ) {}

    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        $zernioAccountId = $this->config->analyticsAccountFor($account);

        if ($zernioAccountId === null) {
            return parent::collect($publication, $date);
        }

        // A TikTok video id, or the Zernio post id of a post Zernio published
        // whose video id TikTok has not reported yet (Zernio accepts both).
        $postId = ctype_digit($publication->remote_id)
            ? $publication->remote_id
            : ZernioTikTokPublisher::zernioPostId($publication->remote_id);

        if ($postId === null) {
            return parent::collect($publication, $date);
        }

        $metrics = $this->analytics->metrics(Platform::TikTok, $this->analytics->postAnalytics(Platform::TikTok, $zernioAccountId, $postId));

        return $this->observation($date, $this->withEngagements($metrics));
    }

    /**
     * CollectPublicationMetrics asks this first for a post whose remote id is
     * not a video id yet; a Zernio post's id comes from Zernio.
     */
    public function publicVideoId(AnalyticsPublication $publication): string
    {
        $account = $this->account($publication);

        if ($this->config->analyticsAccountFor($account) === null
            || ZernioTikTokPublisher::zernioPostId($publication->remote_id) === null) {
            return parent::publicVideoId($publication);
        }

        $publisher = app(TikTokPublisher::class);
        $post = $publication->post;
        $videoId = $post !== null && $publisher instanceof ZernioTikTokPublisher
            ? $publisher->publicVideoId($post)
            : null;

        if ($videoId === null || ! ctype_digit($videoId)) {
            throw new AnalyticsCollectionException('delayed', 'TikTok publication has no public video id yet.');
        }

        return $videoId;
    }
}
