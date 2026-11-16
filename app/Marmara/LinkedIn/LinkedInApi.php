<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn;

use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;
use App\Support\Analytics\AnalyticsAccessToken;
use App\Support\Analytics\RetryAfter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * LinkedIn's versioned REST API (Community Management API) for a LinkedIn
 * page channel: `https://api.linkedin.com/rest/...` with `LinkedIn-Version`
 * and `X-Restli-Protocol-Version: 2.0.0`, the channel's own token (refreshed
 * through TryPost's ConnectionVerifier when expired), short timeouts, and the
 * module's daily call budget. Every failure is an AnalyticsCollectionException
 * category TryPost's analytics jobs already act on; tokens are never logged
 * or put in a message.
 *
 * @see https://learn.microsoft.com/linkedin/marketing/versioning
 */
class LinkedInApi
{
    public function __construct(
        private readonly LinkedInConfig $config,
        private readonly LinkedInCallBudget $budget,
    ) {}

    /**
     * Lifetime statistics of the page's posts, keyed by post URN. A post
     * LinkedIn leaves out of the answer had no impressions or actions: its
     * entry is `[]` (all counts 0). The endpoint has no pagination, so the
     * URNs go in batches, one request per batch and URN kind.
     *
     * @see https://learn.microsoft.com/linkedin/marketing/community-management/organizations/share-statistics
     *
     * @param  list<string>  $urns
     * @return array<string, array<string, mixed>>
     */
    public function shareStatistics(SocialAccount $account, array $urns): array
    {
        $organization = $this->organizationUrn($account);
        $kinds = ['share' => [], 'ugcPost' => []];

        foreach (array_unique($urns) as $urn) {
            $kind = self::urnKind($urn);

            if ($kind !== null) {
                $kinds[$kind][] = $urn;
            }
        }

        $statistics = [];

        foreach ($kinds as $kind => $kindUrns) {
            foreach (array_chunk($kindUrns, $this->config->statsBatchSize()) as $batch) {
                $response = $this->get($account, '/rest/organizationalEntityShareStatistics', [
                    'q' => 'organizationalEntity',
                    'organizationalEntity' => rawurlencode($organization),
                    "{$kind}s" => 'List('.implode(',', array_map('rawurlencode', $batch)).')',
                ], 'share statistics');

                foreach ($batch as $urn) {
                    $statistics[$urn] = [];
                }

                foreach ((array) $response->json('elements', []) as $element) {
                    $urn = data_get($element, $kind);
                    $totals = data_get($element, 'totalShareStatistics');

                    if (is_string($urn) && array_key_exists($urn, $statistics) && is_array($totals)) {
                        $statistics[$urn] = $totals;
                    }
                }
            }
        }

        return $statistics;
    }

    /**
     * The page's follower count (first-degree size of the organization).
     *
     * @see https://learn.microsoft.com/linkedin/marketing/community-management/organizations/organization-lookup-api
     */
    public function followerCount(SocialAccount $account): int
    {
        $response = $this->get(
            $account,
            '/rest/networkSizes/'.rawurlencode($this->organizationUrn($account)),
            ['edgeType' => 'COMPANY_FOLLOWED_BY_MEMBER'],
            'follower count',
        );
        $size = $response->json('firstDegreeSize');

        if (! is_numeric($size)) {
            throw AnalyticsCollectionException::malformed('LinkedIn networkSizes carried no firstDegreeSize');
        }

        return (int) $size;
    }

    /**
     * Daily follower gains (organic + paid) between two UTC days, keyed by
     * date. LinkedIn keeps 12 months and reports up to two days ago.
     *
     * @see https://learn.microsoft.com/linkedin/marketing/community-management/organizations/follower-statistics
     *
     * @return array<string, int>
     */
    public function followerGains(SocialAccount $account, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $gains = [];
        $windowStart = $from->startOfDay();

        while ($windowStart->lessThan($to)) {
            $windowEnd = $windowStart->addDays(90)->min($to);
            $start = 0;

            for ($page = 0; $page < 5; $page++) {
                $response = $this->get($account, '/rest/organizationalEntityFollowerStatistics', array_filter([
                    'q' => 'organizationalEntity',
                    'organizationalEntity' => rawurlencode($this->organizationUrn($account)),
                    'timeIntervals' => sprintf(
                        '(timeRange:(start:%d,end:%d),timeGranularityType:DAY)',
                        $windowStart->getTimestampMs(),
                        $windowEnd->getTimestampMs(),
                    ),
                    'start' => $start > 0 ? (string) $start : null,
                ]), 'follower statistics');
                $elements = (array) $response->json('elements', []);

                foreach ($elements as $element) {
                    $day = data_get($element, 'timeRange.start');

                    if (is_numeric($day)) {
                        $date = CarbonImmutable::createFromTimestampMsUTC((int) $day)->toDateString();
                        $gains[$date] = (int) data_get($element, 'followerGains.organicFollowerGain', 0)
                            + (int) data_get($element, 'followerGains.paidFollowerGain', 0);
                    }
                }

                $start += count($elements);
                $total = $response->json('paging.total');

                if ($elements === [] || ! is_numeric($total) || $start >= (int) $total) {
                    break;
                }
            }

            $windowStart = $windowEnd;
        }

        ksort($gains);

        return $gains;
    }

    /**
     * One page of the organization's posts, most recently modified first
     * (`LAST_MODIFIED`, LinkedIn's default; `CREATED` would put a draft
     * published today at its creation date).
     *
     * @see https://learn.microsoft.com/linkedin/marketing/community-management/shares/posts-api
     *
     * @return array{elements: list<array<string, mixed>>, hasMore: bool}
     */
    public function posts(SocialAccount $account, int $start, int $count): array
    {
        $response = $this->get($account, '/rest/posts', [
            'q' => 'author',
            'author' => rawurlencode($this->organizationUrn($account)),
            'sortBy' => 'LAST_MODIFIED',
            'count' => (string) $count,
            'start' => (string) $start,
        ], 'posts');
        $elements = array_values(array_filter((array) $response->json('elements', []), 'is_array'));
        $hasNextLink = collect((array) $response->json('paging.links', []))
            ->contains(fn (mixed $link): bool => data_get($link, 'rel') === 'next');

        return [
            'elements' => $elements,
            'hasMore' => $elements !== [] && ($hasNextLink || count($elements) >= $count),
        ];
    }

    /**
     * Lifetime video views of a video post (`urn:li:ugcPost:` only, about six
     * months of data). Null when LinkedIn reports nothing.
     *
     * @see https://learn.microsoft.com/linkedin/marketing/community-management/shares/video-analytics-api
     */
    public function videoViews(SocialAccount $account, string $urn): ?int
    {
        $response = $this->get($account, '/rest/videoAnalytics', [
            'q' => 'entity',
            'entity' => rawurlencode($urn),
            'type' => 'VIDEO_VIEW',
            'aggregation' => 'ALL',
        ], 'video analytics');
        $values = array_filter(
            array_map(fn (mixed $element): mixed => data_get($element, 'value'), (array) $response->json('elements', [])),
            'is_numeric',
        );

        return $values === [] ? null : (int) array_sum($values);
    }

    /**
     * The token member's approved ADMINISTRATOR roles (organization URNs).
     *
     * @see https://learn.microsoft.com/linkedin/marketing/community-management/organizations/organization-access-control-by-role
     *
     * @return list<string>
     */
    public function administeredOrganizations(SocialAccount $account): array
    {
        $response = $this->get($account, '/rest/organizationAcls', [
            'q' => 'roleAssignee',
            'role' => 'ADMINISTRATOR',
            'state' => 'APPROVED',
        ], 'organization roles');

        return array_values(array_filter(array_map(
            fn (mixed $element): mixed => data_get($element, 'organization') ?? data_get($element, 'organizationTarget'),
            (array) $response->json('elements', []),
        ), 'is_string'));
    }

    public static function urnKind(string $urn): ?string
    {
        return match (true) {
            str_starts_with($urn, 'urn:li:share:') => 'share',
            str_starts_with($urn, 'urn:li:ugcPost:') => 'ugcPost',
            default => null,
        };
    }

    private function organizationUrn(SocialAccount $account): string
    {
        return NativeLinkedInAccounts::organizationUrn($account)
            ?? throw AnalyticsCollectionException::unsupported('The LinkedIn page channel has no organization id');
    }

    /**
     * @param  array<string, string>  $query  values already encoded for Rest.li 2.0
     */
    private function get(SocialAccount $account, string $path, array $query, string $operation): Response
    {
        $token = AnalyticsAccessToken::for($account);
        $this->budget->take();
        $url = $this->config->api().$path.'?'.implode('&', array_map(
            fn (string $key, string $value): string => "{$key}={$value}",
            array_keys($query),
            $query,
        ));

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->withHeaders([
                    'LinkedIn-Version' => $this->config->version(),
                    'X-Restli-Protocol-Version' => '2.0.0',
                ])
                ->connectTimeout($this->config->connectTimeout())
                ->timeout($this->config->timeout())
                ->get($url);
        } catch (ConnectionException) {
            throw new AnalyticsCollectionException('transient', "LinkedIn {$operation}: no answer in time");
        }

        return $this->successful($response, $operation);
    }

    private function successful(Response $response, string $operation): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();
        $detail = $this->detail($response);

        if ($status === 429) {
            $retryAt = RetryAfter::from($response)
                ?? CarbonImmutable::now('UTC')->addMinutes($this->config->backoffMinutes());
            $this->budget->block($retryAt);

            throw new AnalyticsCollectionException('rate_limited', "LinkedIn {$operation}: rate limited (HTTP 429)", $retryAt);
        }

        [$category, $message] = match (true) {
            $status === 401 => ['authentication', 'LinkedIn refused the token (expired or revoked): reconnect the LinkedIn page in TryPost'],
            $status === 403 => ['permission', 'LinkedIn refused access (missing scope or not a page ADMINISTRATOR)'],
            $response->serverError() => ['transient', 'LinkedIn had a server error'],
            default => ['malformed', 'LinkedIn refused the request'],
        };

        // A 404 is never reported as a deleted post: a wrong version or path
        // answers 404 too, and a deletion would end the post's measurements.
        throw new AnalyticsCollectionException(
            $category,
            "LinkedIn {$operation} failed with HTTP {$status}: {$message}".($detail !== '' ? " ({$detail})" : ''),
        );
    }

    /**
     * LinkedIn's own error text (never request data), shortened.
     */
    private function detail(Response $response): string
    {
        $message = data_get($response->json(), 'message');

        return is_string($message) ? mb_substr(trim($message), 0, 200) : '';
    }
}
