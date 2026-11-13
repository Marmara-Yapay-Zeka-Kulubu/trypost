<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Analytics;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\MetricValue;
use App\Enums\Analytics\MetricAvailability;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricPrecision;
use App\Enums\Analytics\MetricTimeBasis;
use App\Enums\Analytics\MetricUnit;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Marmara\Zernio\Exceptions\ZernioPublishException;
use App\Marmara\Zernio\ZernioClient;
use App\Marmara\Zernio\ZernioConfig;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reads a mapped account's posts, post metrics and followers from Zernio's
 * stored analytics (TikTok, LinkedIn Page). `GET /v1/analytics` lists the
 * posts published through Zernio and the ones Zernio syncs from the platform
 * (`source=all`: posts the club published natively, before or without
 * Zernio), up to ~12 months back and at most 366 days per request.
 *
 * @see https://docs.zernio.com/analytics/get-analytics
 * @see https://docs.zernio.com/accounts/get-follower-stats
 */
class ZernioPostAnalytics
{
    /** Zernio's maximum range for one analytics request. */
    public const int MAX_RANGE_DAYS = 365;

    private const string CACHE_PREFIX = 'marmara-zernio:post-analytics:';

    public function __construct(
        private readonly ZernioClient $client,
        private readonly ZernioConfig $config,
    ) {}

    /**
     * One page of the account's posts, newest first, published on or after
     * `$since`. Each post's metrics are cached for the metrics read.
     *
     * @return array{publications: list<DiscoveredPublication>, pages: int, skipped: int}
     */
    public function page(SocialAccount $account, string $zernioAccountId, CarbonImmutable $since, int $page): array
    {
        $response = $this->call(fn (): Response => $this->client->analytics([
            'platform' => ZernioConfig::zernioPlatform($account->platform),
            'accountId' => $zernioAccountId,
            'source' => 'all',
            'fromDate' => $since->toDateString(),
            'toDate' => CarbonImmutable::now('UTC')->toDateString(),
            'sortBy' => 'date',
            'order' => 'desc',
            'limit' => $this->config->analyticsPageSize(),
            'page' => $page,
        ]), 'post analytics list');

        $publications = [];
        $skipped = 0;

        foreach ((array) $response->json('posts', []) as $row) {
            $publication = is_array($row) ? $this->publication($account, $zernioAccountId, $row, $since) : null;

            if ($publication === null) {
                $skipped++;

                continue;
            }

            $publications[] = $publication;
        }

        return [
            'publications' => $publications,
            'pages' => max(1, (int) $response->json('pagination.pages', 1)),
            'skipped' => $skipped,
        ];
    }

    /**
     * The metrics of one post: from the last history page that listed it,
     * else one `GET /v1/analytics?postId=` (the platform's post id or a
     * Zernio post id).
     *
     * @return array<string, mixed>
     */
    public function postAnalytics(Platform $platform, string $zernioAccountId, string $postId): array
    {
        $cached = Cache::get($this->cacheKey($zernioAccountId, $postId));

        if (is_array($cached)) {
            return $cached;
        }

        $body = (array) $this->call(fn (): Response => $this->client->analytics([
            'postId' => $postId,
            'accountId' => $zernioAccountId,
        ]), 'post analytics')->json();

        $target = $this->platformEntry((array) data_get($body, 'platformAnalytics', []), ZernioConfig::zernioPlatform($platform), $zernioAccountId);
        $this->assertReady(data_get($target, 'syncStatus') ?? data_get($body, 'syncStatus'));
        $analytics = data_get($target, 'analytics') ?? data_get($body, 'analytics');

        if (! is_array($analytics)) {
            throw AnalyticsCollectionException::malformed('Zernio post analytics carried no metrics.');
        }

        return $analytics;
    }

    /**
     * Zernio's sync state of a post's analytics (`synced`, `pending`,
     * `partial`, `unavailable`). Absent counts as synced.
     */
    private function isReady(mixed $syncStatus): bool
    {
        return $syncStatus === null || $syncStatus === '' || $syncStatus === 'synced';
    }

    /**
     * A post whose analytics Zernio has not synced yet is read again later
     * (`delayed`, like a network that has not produced metrics yet); one
     * Zernio can never sync (`unavailable`) is refused as unsupported, which
     * ends its baseline reads after a few attempts. Nothing is written.
     */
    private function assertReady(mixed $syncStatus): void
    {
        if ($this->isReady($syncStatus)) {
            return;
        }

        throw $syncStatus === 'unavailable'
            ? AnalyticsCollectionException::unsupported('Zernio cannot sync analytics for this post')
            : new AnalyticsCollectionException('delayed', "Zernio post analytics are {$syncStatus}");
    }

    /**
     * The latest follower count Zernio holds for the account on or before
     * `$date`, and the daily series (Zernio refreshes followers once a day).
     *
     * @return array{current: ?int, series: array<string, int>}
     */
    public function followers(string $zernioAccountId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $body = (array) $this->call(fn (): Response => $this->client->followerStats([
            'accountIds' => $zernioAccountId,
            'fromDate' => $from->max($to->subDays(self::MAX_RANGE_DAYS))->toDateString(),
            'toDate' => $to->toDateString(),
            'granularity' => 'daily',
        ]), 'follower stats')->json();

        $series = [];

        foreach ((array) data_get($body, "stats.{$zernioAccountId}", []) as $point) {
            $date = data_get($point, 'date');
            $followers = data_get($point, 'followers');

            if (is_string($date) && is_numeric($followers)) {
                $series[substr($date, 0, 10)] = (int) $followers;
            }
        }

        ksort($series);

        $account = collect((array) data_get($body, 'accounts', []))
            ->first(fn (mixed $item): bool => is_array($item) && (data_get($item, '_id') ?? data_get($item, 'id')) === $zernioAccountId);
        $current = data_get($account, 'currentFollowers');

        return [
            'current' => is_numeric($current) ? (int) $current : ($series === [] ? null : (int) end($series)),
            'series' => $series,
        ];
    }

    /**
     * Zernio's post metrics as TryPost metric values (lifetime counts).
     *
     * TikTok: views, likes, comments and shares are what every TikTok
     * connection reports; the rest only come with Zernio's TikTok for Business
     * connection and are 0 otherwise, so a 0 there means "not reported" and is
     * left out. LinkedIn pages: impressions, unique impressions (reach),
     * reactions, comments, reshares and clicks; video views when reported.
     *
     * @param  array<string, mixed>  $analytics
     * @return list<MetricValue>
     */
    public function metrics(Platform $platform, array $analytics): array
    {
        $metrics = $platform === Platform::TikTok ? [
            $this->metric(MetricKey::Views, $analytics, 'views'),
            $this->metric(MetricKey::Reactions, $analytics, 'likes'),
            $this->metric(MetricKey::Comments, $analytics, 'comments'),
            $this->metric(MetricKey::Shares, $analytics, 'shares'),
            $this->metric(MetricKey::Impressions, $analytics, 'impressions', onlyPositive: true),
            $this->metric(MetricKey::Reach, $analytics, 'reach', onlyPositive: true),
            $this->metric(MetricKey::Saves, $analytics, 'saves', onlyPositive: true),
            $this->metric(MetricKey::Follows, $analytics, 'follows', onlyPositive: true),
            $this->metric(MetricKey::ProfileVisits, $analytics, 'profileViews', onlyPositive: true),
            $this->metric(MetricKey::WatchTimeMilliseconds, $analytics, 'igReelsVideoViewTotalTime', MetricUnit::Milliseconds, onlyPositive: true),
            $this->metric(MetricKey::AverageWatchTimeMilliseconds, $analytics, 'igReelsAvgWatchTime', MetricUnit::Milliseconds, onlyPositive: true),
        ] : [
            $this->metric(MetricKey::Impressions, $analytics, 'impressions'),
            $this->metric(MetricKey::Reach, $analytics, 'reach'),
            $this->metric(MetricKey::Reactions, $analytics, 'likes'),
            $this->metric(MetricKey::Comments, $analytics, 'comments'),
            $this->metric(MetricKey::Shares, $analytics, 'shares'),
            $this->metric(MetricKey::Clicks, $analytics, 'clicks'),
            $this->metric(MetricKey::Views, $analytics, 'views', onlyPositive: true),
        ];

        return array_values(array_filter($metrics, fn (?MetricValue $metric): bool => $metric !== null));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function publication(SocialAccount $account, string $zernioAccountId, array $row, CarbonImmutable $since): ?DiscoveredPublication
    {
        $tiktok = $account->platform === Platform::TikTok;
        $entry = $this->platformEntry((array) data_get($row, 'platforms', []), ZernioConfig::zernioPlatform($account->platform), $zernioAccountId);
        $url = data_get($entry, 'platformPostUrl') ?? data_get($row, 'platformPostUrl');
        $postId = $tiktok
            ? $this->videoId(data_get($entry, 'platformPostId'), $url)
            : $this->platformPostId(data_get($entry, 'platformPostId'));
        $publishedAt = $this->date(data_get($row, 'publishedAt'));

        if ($postId === null || $publishedAt === null || $publishedAt->lessThan($since->startOfDay())) {
            return null;
        }

        $analytics = data_get($entry, 'analytics') ?? data_get($row, 'analytics');

        // Zeros of a copy Zernio has not synced yet are not measurements: not
        // cached, so the metrics read asks again and waits (assertReady()).
        if (is_array($analytics) && $this->isReady(data_get($entry, 'syncStatus')) && $this->config->analyticsCacheMinutes() > 0) {
            Cache::put($this->cacheKey($zernioAccountId, $postId), $analytics, now()->addMinutes($this->config->analyticsCacheMinutes()));
        }

        $mediaType = data_get($row, 'mediaType');
        $contentType = match ($mediaType) {
            'image' => PublicationContentType::Image,
            'carousel' => PublicationContentType::Carousel,
            'video' => PublicationContentType::Video,
            'text' => $tiktok ? null : PublicationContentType::Text,
            default => null,
        };
        $thumbnail = data_get($row, 'thumbnailUrl');

        return new DiscoveredPublication(
            providerPostId: $postId,
            publishedAt: $publishedAt,
            contentType: $contentType ?? ($tiktok ? PublicationContentType::Video : PublicationContentType::Unknown),
            providerContentType: is_string($mediaType) ? $mediaType : ($tiktok ? 'video' : null),
            permalink: is_string($url) && $url !== '' ? $url : $this->permalink($account, $postId),
            excerpt: is_string(data_get($row, 'content')) ? data_get($row, 'content') : null,
            // Zernio serves covers from its own storage: the URL does not expire.
            previewMetadata: is_string($thumbnail) && $thumbnail !== '' ? ['thumbnail_url' => $thumbnail, 'expires_at' => null] : null,
            providerMetadata: array_filter([
                'zernio_post_id' => data_get($row, '_id'),
                'zernio_source' => data_get($row, 'isExternal') === true ? 'external' : 'zernio',
            ], fn (mixed $value): bool => is_string($value) && $value !== ''),
            contentTypeReported: $contentType !== null,
        );
    }

    private function permalink(SocialAccount $account, string $postId): ?string
    {
        if ($account->platform !== Platform::TikTok) {
            return str_starts_with($postId, 'urn:li:') ? "https://www.linkedin.com/feed/update/{$postId}" : null;
        }

        $username = ltrim((string) $account->username, '@');

        return $username !== '' ? "https://www.tiktok.com/@{$username}/video/{$postId}" : null;
    }

    /**
     * A LinkedIn post URN (urn:li:share:…, urn:li:ugcPost:…), the id TryPost's
     * LinkedIn publisher stores too.
     */
    private function platformPostId(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * The copy of a post on this platform and account: Zernio lists one entry
     * per platform the post went to.
     *
     * @param  array<int, mixed>  $entries
     * @return array<string, mixed>|null
     */
    private function platformEntry(array $entries, string $zernioPlatform, string $zernioAccountId): ?array
    {
        $copies = array_values(array_filter(
            $entries,
            fn (mixed $entry): bool => is_array($entry) && in_array(data_get($entry, 'platform'), [$zernioPlatform, null], true),
        ));

        foreach ($copies as $entry) {
            $accountId = data_get($entry, 'accountId');
            $accountId = is_array($accountId) ? (data_get($accountId, '_id') ?? data_get($accountId, 'id')) : $accountId;

            if ($accountId === $zernioAccountId) {
                return $entry;
            }
        }

        return $copies[0] ?? null;
    }

    private function videoId(mixed $platformPostId, mixed $url): ?string
    {
        if (is_scalar($platformPostId) && ctype_digit((string) $platformPostId)) {
            return (string) $platformPostId;
        }

        if (is_string($url) && preg_match('~/(?:video|photo)/(\d+)~', $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $analytics
     */
    private function metric(
        MetricKey $key,
        array $analytics,
        string $field,
        MetricUnit $unit = MetricUnit::Count,
        bool $onlyPositive = false,
    ): ?MetricValue {
        $value = data_get($analytics, $field);

        if (! is_numeric($value) || ($onlyPositive && $value <= 0)) {
            return null;
        }

        return new MetricValue(
            key: $key,
            value: (int) round((float) $value),
            unit: $unit,
            timeBasis: MetricTimeBasis::Lifetime,
            precision: MetricPrecision::Exact,
            availability: MetricAvailability::Available,
            providerMetric: 'zernio.'.$field,
        );
    }

    private function cacheKey(string $zernioAccountId, string $postId): string
    {
        return self::CACHE_PREFIX.$zernioAccountId.':'.$postId;
    }

    /**
     * @param  Closure(): Response  $request
     */
    private function call(Closure $request, string $operation): Response
    {
        try {
            $response = $request();
        } catch (ZernioPublishException) {
            // Only thrown before any request: ZERNIO_API_KEY is not set.
            throw new AnalyticsCollectionException('authentication', 'ZERNIO_API_KEY is not set');
        }

        return ZernioAnalyticsResponse::successful($response, $operation);
    }
}
