<?php

declare(strict_types=1);

use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Marmara\LinkedIn\Analytics\Collectors\NativeLinkedInPagePublicationCollector;
use App\Marmara\LinkedIn\NativeLinkedInAccounts;
use App\Marmara\Zernio\Analytics\Collectors\ZernioLinkedInPagePublicationCollector;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Analytics\Collectors\Followers\FollowerCollectorFactory;
use App\Services\Analytics\Collectors\Metrics\PublicationMetricsCollectorFactory;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

const LI_NATIVE_API = 'https://api.linkedin.com';
const LI_NATIVE_ORG = '104857600';
const LI_NATIVE_SHARE_A = 'urn:li:share:7390000000000000001';
const LI_NATIVE_SHARE_B = 'urn:li:share:7390000000000000002';
const LI_NATIVE_VIDEO = 'urn:li:ugcPost:7390000000000000003';
const LI_NATIVE_ZERNIO_ACCOUNT = '7b1c2d3e4f5a6b7c8d9e0f1a';

beforeEach(function () {
    Event::fake();
    Mail::fake();
    Queue::fake();
    CarbonImmutable::setTestNow('2026-11-14 12:00:00 UTC');

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->page = SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => LI_NATIVE_ORG,
        'username' => 'marmaraai',
        'display_name' => 'Marmara Yapay Zeka Kulübü',
        'meta' => ['organization_id' => LI_NATIVE_ORG, 'admin_user_id' => 'member-1'],
        'scopes' => ['rw_organization_admin', 'r_organization_social', 'w_organization_social', 'r_organization_followers', 'r_organization_social_feed'],
        'token_expires_at' => now()->addDays(50),
    ]);

    config()->set('marmara-zernio.enabled', false);
    config()->set('marmara-linkedin.native_analytics', true);
    config()->set('marmara-linkedin.daily_call_budget', 80);
    config()->set('marmara-linkedin.stats_batch_size', 50);
    config()->set('marmara-linkedin.cache_minutes', 720);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function liNativePublication(SocialAccount $page, string $urn, array $attributes = []): AnalyticsPublication
{
    return AnalyticsPublication::factory()->create([
        'workspace_id' => $page->workspace_id,
        'social_account_id' => $page->id,
        'network' => Platform::LinkedInPage->network(),
        'platform_user_id' => $page->platform_user_id,
        'platform' => Platform::LinkedInPage,
        'remote_id' => $urn,
        'provider_published_at' => now()->subDays(3),
        'content_type' => PublicationContentType::Text,
        'provider_content_type' => 'text',
        ...$attributes,
    ]);
}

/**
 * @param  list<array<string, mixed>>  $elements
 * @return array<string, mixed>
 */
function liNativeList(array $elements, bool $next = false): array
{
    return [
        'elements' => $elements,
        'paging' => ['count' => 10, 'start' => 0, 'links' => $next ? [['rel' => 'next', 'href' => '/rest/posts?start=next']] : []],
    ];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function liNativePost(string $urn, string $publishedAt, array $extra = []): array
{
    $ms = CarbonImmutable::parse($publishedAt, 'UTC')->getTimestampMs();

    return [
        'id' => $urn,
        'author' => 'urn:li:organization:'.LI_NATIVE_ORG,
        'createdAt' => $ms,
        'publishedAt' => $ms,
        'lastModifiedAt' => $ms,
        'lifecycleState' => 'PUBLISHED',
        'visibility' => 'PUBLIC',
        'commentary' => 'Marmara AI kulüp duyurusu',
        'distribution' => ['feedDistribution' => 'MAIN_FEED'],
        'content' => [],
        ...$extra,
    ];
}

/**
 * @return array<string, int>
 */
function liNativeMetrics(AnalyticsPublication $publication, SocialAccount $page): array
{
    $observation = app(PublicationMetricsCollectorFactory::class)->for(Platform::LinkedInPage)
        ->collect($publication->setRelation('socialAccount', $page), CarbonImmutable::now('UTC')->startOfDay());

    return collect($observation->metrics)->mapWithKeys(fn ($metric): array => [$metric->key->value => $metric->value])->all();
}

function liNativeZernioConfig(array $linkedinPages): void
{
    config()->set('marmara-zernio', [
        'enabled' => true,
        'api_key' => 'sk_test_key',
        'base_url' => 'https://zernio.com/api/v1',
        'timeout' => 30,
        'accounts' => ['linkedin-page' => $linkedinPages],
        'tiktok' => ['accounts' => [], 'media_mode' => 'url', 'status_poll_seconds' => 60, 'status_window_minutes' => 60],
        'analytics' => ['enabled' => true, 'page_size' => 50, 'cache_minutes' => 360, 'import_limit' => 500],
    ]);
}

test('with the switch off LinkedIn pages keep the previous behaviour and nothing is called', function () {
    config()->set('marmara-linkedin.native_analytics', false);

    expect(Platform::LinkedInPage->isIncludedInAnalytics())->toBeFalse()
        ->and(app(FollowerCollectorFactory::class)->supports(Platform::LinkedInPage))->toBeFalse()
        ->and(app(NativeLinkedInAccounts::class)->uses($this->page))->toBeFalse()
        ->and(fn () => app(PublicationHistoryCollectorFactory::class)->for($this->page))->toThrow(AnalyticsCollectionException::class)
        ->and(fn () => app(PublicationMetricsCollectorFactory::class)->for(Platform::LinkedInPage))->toThrow(AnalyticsCollectionException::class);

    $this->artisan('marmara:linkedin:analytics-backfill')->assertFailed();

    config()->set('marmara-linkedin.native_analytics', true);

    expect(Platform::LinkedInPage->isIncludedInAnalytics())->toBeTrue()
        ->and(Platform::analyticsValues())->toContain('linkedin-page')
        ->and(app(FollowerCollectorFactory::class)->supports(Platform::LinkedInPage))->toBeTrue()
        ->and(app(PublicationHistoryCollectorFactory::class)->for($this->page))->toBeInstanceOf(NativeLinkedInPagePublicationCollector::class);

    Http::assertNothingSent();
});

test('each LinkedIn page is measured either natively or by Zernio, never both', function () {
    $linked = SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'zernio:'.LI_NATIVE_ZERNIO_ACCOUNT,
        'username' => 'marmara-zernio',
        'access_token' => 'zernio-managed:abc',
        'refresh_token' => null,
        'token_expires_at' => null,
        'meta' => ['organization_id' => '555', 'managed_by' => 'zernio', 'zernio_account_id' => LI_NATIVE_ZERNIO_ACCOUNT],
    ]);
    $withoutOrganization = SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'meta' => [],
    ]);
    $accounts = app(NativeLinkedInAccounts::class);

    liNativeZernioConfig([(string) $linked->id => LI_NATIVE_ZERNIO_ACCOUNT]);

    expect($accounts->uses($this->page))->toBeTrue()
        ->and($accounts->reason($linked))->toContain('Zernio')
        ->and($accounts->reason($withoutOrganization))->toContain('organization id')
        ->and(app(PublicationHistoryCollectorFactory::class)->for($this->page))->toBeInstanceOf(NativeLinkedInPagePublicationCollector::class)
        ->and(app(PublicationHistoryCollectorFactory::class)->for($linked))->toBeInstanceOf(ZernioLinkedInPagePublicationCollector::class);

    // A channel connected natively but still in ZERNIO_ACCOUNTS stays with Zernio.
    liNativeZernioConfig([(string) $linked->id => LI_NATIVE_ZERNIO_ACCOUNT, 'marmaraai' => LI_NATIVE_ZERNIO_ACCOUNT]);

    expect($accounts->reason($this->page))->toContain('Zernio')
        ->and(app(PublicationHistoryCollectorFactory::class)->for($this->page))->toBeInstanceOf(ZernioLinkedInPagePublicationCollector::class);

    // The Zernio placeholder token never counts as native, even unmapped.
    config()->set('marmara-zernio.enabled', false);

    expect($accounts->reason($linked))->toContain('no native LinkedIn token')
        ->and($accounts->uses($this->page))->toBeTrue();
});

test('the post metrics of a Zernio-mapped page still come from Zernio', function () {
    $publication = liNativePublication($this->page, LI_NATIVE_SHARE_A);
    liNativeZernioConfig(['marmaraai' => LI_NATIVE_ZERNIO_ACCOUNT]);
    Http::fake([
        'https://zernio.com/api/v1/analytics*' => Http::response(['platformAnalytics' => [[
            'platform' => 'linkedin',
            'accountId' => LI_NATIVE_ZERNIO_ACCOUNT,
            'analytics' => ['impressions' => 10, 'reach' => 8, 'likes' => 1, 'comments' => 0, 'shares' => 0, 'clicks' => 2],
        ]]]),
    ]);

    expect(liNativeMetrics($publication, $this->page))->toMatchArray([MetricKey::Impressions->value => 10]);
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), LI_NATIVE_API));
});

test('share statistics are mapped, batched in one request, and an omitted post counts zero', function () {
    $first = liNativePublication($this->page, LI_NATIVE_SHARE_A);
    $quiet = liNativePublication($this->page, LI_NATIVE_SHARE_B, ['provider_published_at' => now()->subDays(5)]);
    Http::fake([
        LI_NATIVE_API.'/rest/organizationalEntityShareStatistics*' => Http::response(liNativeList([[
            'organizationalEntity' => 'urn:li:organization:'.LI_NATIVE_ORG,
            'share' => LI_NATIVE_SHARE_A,
            'totalShareStatistics' => [
                'impressionCount' => 5287,
                'uniqueImpressionsCount' => 3120,
                'clickCount' => 78,
                'likeCount' => 14,
                'commentCount' => 24,
                'shareCount' => 5,
                'engagement' => 0.0228,
            ],
        ]])),
    ]);

    expect(liNativeMetrics($first, $this->page))->toMatchArray([
        MetricKey::Impressions->value => 5287,
        MetricKey::Reach->value => 3120,
        MetricKey::Clicks->value => 78,
        MetricKey::Reactions->value => 14,
        MetricKey::Comments->value => 24,
        MetricKey::Shares->value => 5,
        MetricKey::Engagements->value => 121,
    ])->not->toHaveKey(MetricKey::Views->value);

    expect(liNativeMetrics($quiet, $this->page))->toMatchArray([
        MetricKey::Impressions->value => 0,
        MetricKey::Reach->value => 0,
        MetricKey::Clicks->value => 0,
        MetricKey::Reactions->value => 0,
        MetricKey::Comments->value => 0,
        MetricKey::Shares->value => 0,
        MetricKey::Engagements->value => 0,
    ]);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $url = rawurldecode($request->url());

        return str_contains($url, '/rest/organizationalEntityShareStatistics?q=organizationalEntity')
            && str_contains($url, 'organizationalEntity=urn:li:organization:'.LI_NATIVE_ORG)
            && str_contains($url, 'shares=List('.LI_NATIVE_SHARE_A.','.LI_NATIVE_SHARE_B.')')
            && $request->hasHeader('LinkedIn-Version', '202610')
            && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0')
            && $request->hasHeader('Authorization');
    });
});

test('the plural uniqueImpressionsCounts field is read as reach too, and a missing one is not invented', function () {
    $publication = liNativePublication($this->page, LI_NATIVE_SHARE_A);
    $other = liNativePublication($this->page, LI_NATIVE_SHARE_B);
    Http::fake([
        LI_NATIVE_API.'/rest/organizationalEntityShareStatistics*' => Http::response(liNativeList([
            ['share' => LI_NATIVE_SHARE_A, 'totalShareStatistics' => ['impressionCount' => 40, 'uniqueImpressionsCounts' => 31, 'clickCount' => 1, 'likeCount' => 2, 'commentCount' => 0, 'shareCount' => 0]],
            ['share' => LI_NATIVE_SHARE_B, 'totalShareStatistics' => ['impressionCount' => 9, 'clickCount' => 0, 'likeCount' => -1, 'commentCount' => 0, 'shareCount' => 0]],
        ])),
    ]);

    expect(liNativeMetrics($publication, $this->page))->toMatchArray([MetricKey::Reach->value => 31, MetricKey::Impressions->value => 40])
        ->and(liNativeMetrics($other, $this->page))
        ->not->toHaveKey(MetricKey::Reach->value)
        ->toMatchArray([MetricKey::Impressions->value => 9, MetricKey::Reactions->value => 0]);
});

test('a recent video post also reports its video views, an old one does not ask', function () {
    $video = liNativePublication($this->page, LI_NATIVE_VIDEO, ['content_type' => PublicationContentType::Video, 'provider_content_type' => 'video', 'provider_published_at' => now()->subDays(10)]);
    $oldVideo = liNativePublication($this->page, 'urn:li:ugcPost:7300000000000000009', ['content_type' => PublicationContentType::Video, 'provider_content_type' => 'video', 'provider_published_at' => now()->subDays(200)]);
    Http::fake([
        LI_NATIVE_API.'/rest/organizationalEntityShareStatistics*' => Http::response(liNativeList([
            ['ugcPost' => LI_NATIVE_VIDEO, 'totalShareStatistics' => ['impressionCount' => 900, 'uniqueImpressionsCount' => 700, 'clickCount' => 12, 'likeCount' => 30, 'commentCount' => 4, 'shareCount' => 2]],
        ])),
        LI_NATIVE_API.'/rest/videoAnalytics*' => Http::response(liNativeList([
            ['entity' => LI_NATIVE_VIDEO, 'statisticsType' => 'VIDEO_VIEW', 'value' => 321],
        ])),
    ]);

    expect(liNativeMetrics($video, $this->page))->toMatchArray([
        MetricKey::Views->value => 321,
        MetricKey::Impressions->value => 900,
        MetricKey::Engagements->value => 48,
    ])->and(liNativeMetrics($oldVideo, $this->page))->not->toHaveKey(MetricKey::Views->value);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains(rawurldecode($request->url()), '/rest/videoAnalytics?q=entity&entity='.LI_NATIVE_VIDEO.'&type=VIDEO_VIEW&aggregation=ALL'));
    Http::assertSent(fn (Request $request): bool => str_contains(rawurldecode($request->url()), 'ugcPosts=List('.LI_NATIVE_VIDEO.',urn:li:ugcPost:7300000000000000009)'));
});

test('the daily follower count comes from networkSizes', function () {
    Http::fake([
        LI_NATIVE_API.'/rest/networkSizes/*' => Http::response(['firstDegreeSize' => 1234]),
    ]);

    app()->call([new CollectAccountDailySnapshot($this->page->id, '2026-11-14'), 'handle']);

    expect(AnalyticsAccountDailySnapshot::query()->where('social_account_id', $this->page->id)->sole()->followers_count)->toBe(1234);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/rest/networkSizes/urn%3Ali%3Aorganization%3A'.LI_NATIVE_ORG.'?edgeType=COMPANY_FOLLOWED_BY_MEMBER'));
});

test('the backfill pages through the posts finder, skips dark posts and stops at the last page', function () {
    config()->set('marmara-linkedin.page_size', 3);
    Http::fake([
        LI_NATIVE_API.'/rest/posts*' => Http::sequence()
            ->push(liNativeList([
                liNativePost(LI_NATIVE_VIDEO, '2026-11-13 09:00:00', ['content' => ['media' => ['id' => 'urn:li:video:C1']]]),
                liNativePost('urn:li:share:7390000000000000099', '2026-11-12 09:00:00', ['distribution' => ['feedDistribution' => 'NONE']]),
                liNativePost(LI_NATIVE_SHARE_A, '2026-11-10 09:00:00', ['content' => ['media' => ['id' => 'urn:li:image:C2']]]),
            ], next: true))
            ->push(liNativeList([
                liNativePost(LI_NATIVE_SHARE_B, '2026-08-01 09:00:00'),
            ])),
    ]);

    $this->artisan('marmara:linkedin:analytics-backfill', ['--days' => 365, '--skip-followers' => true, '--import-limit' => 0])
        ->assertSuccessful();

    $publications = AnalyticsPublication::query()->where('social_account_id', $this->page->id)->get()->keyBy('remote_id');
    $state = AnalyticsSyncState::query()->where('social_account_id', $this->page->id)->forCollector(SyncCollector::PublicationBackfill)->sole();

    expect($publications->keys()->sort()->values()->all())->toBe([LI_NATIVE_SHARE_A, LI_NATIVE_SHARE_B, LI_NATIVE_VIDEO])
        ->and($publications[LI_NATIVE_VIDEO]->content_type)->toBe(PublicationContentType::Video)
        ->and($publications[LI_NATIVE_SHARE_A]->content_type)->toBe(PublicationContentType::Image)
        ->and($publications[LI_NATIVE_SHARE_B]->content_type)->toBe(PublicationContentType::Text)
        ->and($state->status)->toBe(SyncStatus::Complete);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains(rawurldecode($request->url()), '/rest/posts?q=author&author=urn:li:organization:'.LI_NATIVE_ORG.'&sortBy=CREATED&count=3&start=0'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '&count=3&start=3'));
});

test('a backfill stopped by a 429 keeps its cursor and resumes there', function () {
    config()->set('marmara-linkedin.page_size', 2);
    Http::fake([
        LI_NATIVE_API.'/rest/posts*' => Http::sequence()
            ->push(liNativeList([
                liNativePost(LI_NATIVE_SHARE_A, '2026-11-10 09:00:00'),
                liNativePost(LI_NATIVE_SHARE_B, '2026-11-01 09:00:00'),
            ], next: true))
            ->push(['message' => 'Resource level throttle limit exceeded'], 429, ['Retry-After' => '3600'])
            ->push(liNativeList([
                liNativePost(LI_NATIVE_VIDEO, '2026-10-01 09:00:00'),
            ])),
    ]);

    $this->artisan('marmara:linkedin:analytics-backfill', ['--skip-followers' => true, '--import-limit' => 0])
        ->expectsOutputToContain('--resume')
        ->assertSuccessful();

    $state = AnalyticsSyncState::query()->where('social_account_id', $this->page->id)->forCollector(SyncCollector::PublicationBackfill)->sole();

    expect($state->status)->toBe(SyncStatus::Running)
        ->and(data_get($state->checkpoint, 'cursor'))->toBe('2')
        ->and(AnalyticsPublication::query()->where('social_account_id', $this->page->id)->count())->toBe(2);

    // While the back-off lasts nothing is sent.
    $this->artisan('marmara:linkedin:analytics-backfill', ['--skip-followers' => true, '--import-limit' => 0, '--resume' => true])->assertSuccessful();
    Http::assertSentCount(2);

    $this->travel(2)->hours();

    $this->artisan('marmara:linkedin:analytics-backfill', ['--skip-followers' => true, '--import-limit' => 0, '--resume' => true])
        ->assertSuccessful();

    expect($state->fresh()->status)->toBe(SyncStatus::Complete)
        ->and(AnalyticsPublication::query()->where('social_account_id', $this->page->id)->count())->toBe(3);
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '&count=2&start=2'));
});

test('a 429 on post statistics backs off without calling LinkedIn again until Retry-After', function () {
    $publication = liNativePublication($this->page, LI_NATIVE_SHARE_A);
    Http::fake([
        LI_NATIVE_API.'/rest/organizationalEntityShareStatistics*' => Http::response(['message' => 'Too many requests'], 429, ['Retry-After' => '600']),
    ]);

    foreach ([1, 2] as $attempt) {
        expect(fn () => liNativeMetrics($publication, $this->page))->toThrow(function (AnalyticsCollectionException $exception): void {
            expect($exception->category)->toBe('rate_limited')
                ->and($exception->retryAt?->toDateTimeString())->toBe('2026-11-14 12:10:00');
        });
    }

    Http::assertSentCount(1);
});

test('the daily call budget turns further reads into rate_limited until the next UTC day', function () {
    config()->set('marmara-linkedin.daily_call_budget', 1);
    Http::fake([
        LI_NATIVE_API.'/rest/networkSizes/*' => Http::response(['firstDegreeSize' => 1234]),
    ]);
    $collector = app(FollowerCollectorFactory::class)->for(Platform::LinkedInPage);

    expect($collector->collect($this->page, CarbonImmutable::now('UTC'))->followers)->toBe(1234)
        ->and(fn () => $collector->collect($this->page, CarbonImmutable::now('UTC')))->toThrow(function (AnalyticsCollectionException $exception): void {
            expect($exception->category)->toBe('rate_limited')
                ->and($exception->retryAt?->toDateTimeString())->toBe('2026-11-15 00:05:00');
        });

    Http::assertSentCount(1);
});

test('an expired token without a refresh token is an authentication failure, never a request', function () {
    $this->page->forceFill(['token_expires_at' => now()->subDay(), 'refresh_token' => null])->save();
    $publication = liNativePublication($this->page, LI_NATIVE_SHARE_A);
    Http::fake();

    expect(fn () => liNativeMetrics($publication, $this->page->fresh()))->toThrow(function (AnalyticsCollectionException $exception): void {
        expect($exception->category)->toBe('authentication');
    });

    Http::assertNothingSent();
});

test('a token LinkedIn refuses or a missing scope give a clear state', function () {
    $publication = liNativePublication($this->page, LI_NATIVE_SHARE_A);
    Http::fake([
        LI_NATIVE_API.'/rest/organizationalEntityShareStatistics*' => Http::response(['message' => 'Invalid access token', 'serviceErrorCode' => 65601], 401),
    ]);

    expect(fn () => liNativeMetrics($publication, $this->page))->toThrow(function (AnalyticsCollectionException $exception): void {
        expect($exception->category)->toBe('authentication')
            ->and($exception->getMessage())->toContain('reconnect');
    });

    $this->page->forceFill(['scopes' => ['w_organization_social']])->save();

    expect(fn () => liNativeMetrics($publication, $this->page->fresh()))->toThrow(function (AnalyticsCollectionException $exception): void {
        expect($exception->category)->toBe('permission');
    })->and(app(PublicationHistoryCollectorFactory::class)->for($this->page)->page($this->page->fresh(), null, now()->subYear()->toImmutable())->providerLimited)->toBeTrue();

    Http::assertSentCount(1);
});

test('discovery reads LinkedIn\'s post list at most once per interval', function () {
    Http::fake([
        LI_NATIVE_API.'/rest/posts*' => Http::response(liNativeList([liNativePost(LI_NATIVE_SHARE_A, '2026-11-14 09:00:00')])),
    ]);
    $collector = app(PublicationHistoryCollectorFactory::class)->for($this->page);
    $cutoff = CarbonImmutable::now('UTC')->subHours(6);

    expect($collector->page($this->page, null, $cutoff)->publications)->toHaveCount(1)
        ->and($collector->page($this->page, null, $cutoff)->publications)->toBe([]);

    $this->travel(13)->hours();

    expect($collector->page($this->page, null, $cutoff)->publications)->toHaveCount(1);
    Http::assertSentCount(2);
});

test('marmara:linkedin:test checks the admin role, followers and latest posts', function () {
    Http::fake([
        LI_NATIVE_API.'/rest/organizationAcls*' => Http::response(liNativeList([
            ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => 'urn:li:organization:'.LI_NATIVE_ORG, 'roleAssignee' => 'urn:li:person:member-1'],
        ])),
        LI_NATIVE_API.'/rest/networkSizes/*' => Http::response(['firstDegreeSize' => 1234]),
        LI_NATIVE_API.'/rest/posts*' => Http::response(liNativeList([liNativePost(LI_NATIVE_SHARE_A, '2026-11-10 09:00:00')])),
        LI_NATIVE_API.'/rest/organizationalEntityShareStatistics*' => Http::response(liNativeList([
            ['share' => LI_NATIVE_SHARE_A, 'totalShareStatistics' => ['impressionCount' => 77, 'clickCount' => 3, 'likeCount' => 5, 'commentCount' => 1, 'shareCount' => 0]],
        ])),
    ]);

    $this->artisan('marmara:linkedin:test')
        ->expectsOutputToContain('ADMINISTRATOR of urn:li:organization:'.LI_NATIVE_ORG)
        ->expectsOutputToContain('Followers: 1,234')
        ->assertSuccessful();

    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/rest/organizationAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED'));
});

test('align-page-id moves a Zernio-linked page to its organization id with its analytics', function () {
    $linked = SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'zernio:'.LI_NATIVE_ZERNIO_ACCOUNT,
        'username' => 'marmara-zernio',
        'meta' => ['organization_id' => '555', 'managed_by' => 'zernio'],
    ]);
    $publication = liNativePublication($linked, LI_NATIVE_SHARE_A);

    $this->artisan('marmara:linkedin:align-page-id', ['account' => $linked->id])->assertSuccessful();

    expect($linked->fresh()->platform_user_id)->toBe('555')
        ->and($publication->fresh()->platform_user_id)->toBe('555');

    // A second card already holding the organization id is reported, not merged.
    $this->artisan('marmara:linkedin:align-page-id', ['account' => 'marmaraai'])->assertSuccessful();
    $this->page->forceFill(['platform_user_id' => 'other'])->save();
    SocialAccount::factory()->linkedinPage()->create(['workspace_id' => $this->workspace->id, 'platform_user_id' => LI_NATIVE_ORG]);

    $this->artisan('marmara:linkedin:align-page-id', ['account' => 'marmaraai'])->assertFailed();
    Http::assertNothingSent();
});
