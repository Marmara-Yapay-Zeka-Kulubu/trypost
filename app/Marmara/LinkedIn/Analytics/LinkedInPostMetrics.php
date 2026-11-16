<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Analytics;

use App\Dto\Analytics\MetricValue;
use App\Enums\Analytics\MetricAvailability;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricPrecision;
use App\Enums\Analytics\MetricTimeBasis;
use App\Enums\Analytics\MetricUnit;
use App\Enums\Analytics\PublicationAvailability;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Marmara\LinkedIn\LinkedInApi;
use App\Marmara\LinkedIn\LinkedInConfig;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * A LinkedIn page post's lifetime statistics, read in batches: the first
 * post read of the day asks LinkedIn for it and for the account's other
 * recent posts that are not cached yet (one request per batch and URN kind),
 * and the following reads come from the cache. A post LinkedIn leaves out of
 * the answer had no activity and counts 0.
 */
class LinkedInPostMetrics
{
    private const string CACHE_PREFIX = 'marmara-linkedin:stats:';

    /** How many recent publications are considered to fill a batch. */
    private const int PEER_CANDIDATES = 500;

    public function __construct(
        private readonly LinkedInApi $api,
        private readonly LinkedInConfig $config,
    ) {}

    /**
     * @return array<string, mixed> LinkedIn's totalShareStatistics (`[]` = no activity)
     */
    public function statistics(SocialAccount $account, string $urn): array
    {
        $cached = Cache::get($this->cacheKey($account, $urn));

        if (is_array($cached)) {
            return $cached;
        }

        $kind = LinkedInApi::urnKind($urn)
            ?? throw AnalyticsCollectionException::unsupported('Not a LinkedIn post URN (urn:li:share: / urn:li:ugcPost:)');
        $batch = [$urn, ...$this->uncachedPeers($account, $urn, $kind, $this->config->statsBatchSize() - 1)];
        $statistics = $this->api->shareStatistics($account, $batch);

        if ($this->config->cacheMinutes() > 0) {
            foreach ($statistics as $postUrn => $totals) {
                Cache::put($this->cacheKey($account, $postUrn), $totals, now()->addMinutes($this->config->cacheMinutes()));
            }
        }

        return $statistics[$urn] ?? [];
    }

    /**
     * Warms the cache for these posts with as few requests as possible
     * (backfill and the test command).
     *
     * @param  list<string>  $urns
     * @return array<string, array<string, mixed>>
     */
    public function prefetch(SocialAccount $account, array $urns): array
    {
        $urns = array_values(array_filter($urns, fn (string $urn): bool => LinkedInApi::urnKind($urn) !== null));
        $missing = array_values(array_filter($urns, fn (string $urn): bool => ! is_array(Cache::get($this->cacheKey($account, $urn)))));
        $statistics = $missing === [] ? [] : $this->api->shareStatistics($account, $missing);

        if ($this->config->cacheMinutes() > 0) {
            foreach ($statistics as $postUrn => $totals) {
                Cache::put($this->cacheKey($account, $postUrn), $totals, now()->addMinutes($this->config->cacheMinutes()));
            }
        }

        return collect($urns)
            ->mapWithKeys(fn (string $urn): array => [$urn => $statistics[$urn] ?? (array) Cache::get($this->cacheKey($account, $urn), [])])
            ->all();
    }

    /**
     * Video views of a video post, cached like the statistics.
     */
    public function videoViews(SocialAccount $account, string $urn): ?int
    {
        $key = $this->cacheKey($account, 'video:'.$urn);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return is_int($cached['views'] ?? null) ? $cached['views'] : null;
        }

        $views = $this->api->videoViews($account, $urn);

        if ($this->config->cacheMinutes() > 0) {
            Cache::put($key, ['views' => $views], now()->addMinutes($this->config->cacheMinutes()));
        }

        return $views;
    }

    /**
     * LinkedIn's lifetime counts as TryPost metric values. `$statistics` is
     * an element's totalShareStatistics; `[]` (left out by LinkedIn) is a
     * post with no activity, so every count is 0. A field missing from a
     * returned element (LinkedIn's samples omit unique impressions) is not
     * reported. Engagements are derived by the collector like Zernio's
     * (reactions + comments + shares + clicks).
     *
     * @param  array<string, mixed>  $statistics
     * @return list<MetricValue>
     */
    public function metrics(array $statistics, ?int $videoViews = null): array
    {
        $omitted = $statistics === [];
        $uniqueField = array_key_exists('uniqueImpressionsCount', $statistics) ? 'uniqueImpressionsCount' : 'uniqueImpressionsCounts';
        $metrics = [
            $this->count(MetricKey::Impressions, $statistics, 'impressionCount', $omitted),
            $this->count(MetricKey::Reach, $statistics, $uniqueField, $omitted),
            $this->count(MetricKey::Clicks, $statistics, 'clickCount', $omitted),
            $this->count(MetricKey::Reactions, $statistics, 'likeCount', $omitted),
            $this->count(MetricKey::Comments, $statistics, 'commentCount', $omitted),
            $this->count(MetricKey::Shares, $statistics, 'shareCount', $omitted),
            $videoViews === null ? null : $this->value(MetricKey::Views, $videoViews, 'videoAnalytics.VIDEO_VIEW'),
        ];

        return array_values(array_filter($metrics, fn (?MetricValue $metric): bool => $metric !== null));
    }

    /**
     * Recent publications of the account (same URN kind, inside LinkedIn's
     * 12-month window) whose statistics are not cached, newest first.
     *
     * @return list<string>
     */
    private function uncachedPeers(SocialAccount $account, string $urn, string $kind, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return AnalyticsPublication::query()
            ->where('social_account_id', $account->id)
            ->where('platform', Platform::LinkedInPage)
            ->where('availability', PublicationAvailability::Available)
            ->where('remote_id', 'like', "urn:li:{$kind}:%")
            ->where('remote_id', '!=', $urn)
            ->where('provider_published_at', '>=', CarbonImmutable::now('UTC')->subDays(LinkedInConfig::STATS_WINDOW_DAYS))
            ->orderByDesc('provider_published_at')
            ->limit(self::PEER_CANDIDATES)
            ->pluck('remote_id')
            ->filter(fn (mixed $peer): bool => is_string($peer) && ! is_array(Cache::get($this->cacheKey($account, $peer))))
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $statistics
     */
    private function count(MetricKey $key, array $statistics, string $field, bool $omitted): ?MetricValue
    {
        $value = $omitted ? 0 : data_get($statistics, $field);

        // likeCount can go negative when members unlike a sponsored share.
        return is_numeric($value) ? $this->value($key, max(0, (int) round((float) $value)), $field) : null;
    }

    private function value(MetricKey $key, int $value, string $field): MetricValue
    {
        return new MetricValue(
            key: $key,
            value: $value,
            unit: MetricUnit::Count,
            timeBasis: MetricTimeBasis::Lifetime,
            precision: MetricPrecision::Exact,
            availability: MetricAvailability::Available,
            providerMetric: 'linkedin.'.$field,
        );
    }

    private function cacheKey(SocialAccount $account, string $urn): string
    {
        return self::CACHE_PREFIX.$account->id.':'.$urn;
    }
}
