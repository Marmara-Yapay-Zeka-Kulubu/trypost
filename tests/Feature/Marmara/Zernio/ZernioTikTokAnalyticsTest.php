<?php

declare(strict_types=1);

use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\Post\Origin;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Jobs\Analytics\BackfillAccountPublications;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Analytics\CollectPublicationMetrics;
use App\Jobs\Post\ImportExternalPostMedia;
use App\Marmara\Zernio\Analytics\Collectors\ZernioTikTokFollowerCollector;
use App\Marmara\Zernio\Analytics\Collectors\ZernioTikTokPublicationCollector;
use App\Marmara\Zernio\Analytics\Collectors\ZernioTikTokPublicationMetricsCollector;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\AnalyticsSyncState;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Followers\FollowerCollectorFactory;
use App\Services\Analytics\Collectors\Followers\TikTokFollowerCollector;
use App\Services\Analytics\Collectors\Metrics\PublicationMetricsCollectorFactory;
use App\Services\Analytics\Collectors\Metrics\TikTokPublicationMetricsCollector;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use App\Services\Analytics\Collectors\Publications\TikTokPublicationCollector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-11-14 12:00:00 UTC');

    $this->zernioApi = 'https://zernio.com/api/v1';
    $this->zernioAccountId = '6af764170fb0c006b7a777e1';

    config()->set('marmara-zernio', [
        'enabled' => true,
        'api_key' => 'sk_test_key',
        'base_url' => $this->zernioApi,
        'timeout' => 30,
        'tiktok' => [
            'accounts' => ['marmaraai' => $this->zernioAccountId],
            'media_mode' => 'url',
            'status_poll_seconds' => 60,
            'status_window_minutes' => 60,
        ],
        'analytics' => ['enabled' => true, 'page_size' => 50, 'cache_minutes' => 360, 'import_limit' => 500],
    ]);

    // The club's TikTok app was refused video.list and user.info.stats.
    $this->account = SocialAccount::factory()->tiktok()->create([
        'platform_user_id' => 'tt-marmara',
        'username' => 'marmaraai',
        'display_name' => 'Marmara AI',
        'scopes' => ['user.info.basic', 'video.publish', 'video.upload'],
        'token_expires_at' => now()->addDay(),
    ]);
});

/**
 * @param  array<string, mixed>  $analytics
 * @return array<string, mixed>
 */
function zernioAnalyticsRow(string $id, string $videoId, string $publishedAt, string $mediaType = 'video', array $analytics = [], bool $external = true): array
{
    $analytics = [
        'views' => 1000, 'likes' => 50, 'comments' => 4, 'shares' => 2, 'saves' => 0,
        'impressions' => 0, 'reach' => 0, ...$analytics,
    ];

    return [
        '_id' => $id,
        'latePostId' => $external ? null : 'late-'.$id,
        'content' => "Caption {$id}",
        'publishedAt' => $publishedAt,
        'status' => 'published',
        'analytics' => $analytics,
        'platform' => 'tiktok',
        'platformPostUrl' => "https://www.tiktok.com/@marmaraai/video/{$videoId}",
        'isExternal' => $external,
        'thumbnailUrl' => "https://storage.zernio.test/covers/{$id}.jpg",
        'mediaType' => $mediaType,
        'platforms' => [[
            'platform' => 'tiktok',
            'status' => 'published',
            'platformPostId' => $videoId,
            'accountId' => '6af764170fb0c006b7a777e1',
            'accountUsername' => '@marmaraai',
            'analytics' => $analytics,
            'syncStatus' => 'synced',
            'platformPostUrl' => "https://www.tiktok.com/@marmaraai/video/{$videoId}",
        ]],
    ];
}

/**
 * @param  list<array<string, mixed>>  $posts
 * @return array<string, mixed>
 */
function zernioAnalyticsList(array $posts, int $page = 1, int $pages = 1): array
{
    return [
        'overview' => ['totalPosts' => count($posts), 'publishedPosts' => count($posts)],
        'posts' => $posts,
        'pagination' => ['page' => $page, 'limit' => 50, 'total' => count($posts), 'pages' => $pages],
        'hasAnalyticsAccess' => true,
    ];
}

function zernioRequested(string $path): bool
{
    return Http::recorded(fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), $path))->isNotEmpty();
}

function zernioTikTokPublication(SocialAccount $account, string $remoteId): AnalyticsPublication
{
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => $account->platform,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'remote_id' => $remoteId,
        'content_type' => PublicationContentType::Video,
        'provider_published_at' => CarbonImmutable::now('UTC')->subDays(2),
    ]);

    return $publication->setRelation('socialAccount', $account);
}

test('the backfill job reads historical TikTok posts from Zernio, upserts them and imports them into Sent', function () {
    Http::fake([
        'zernio.com/api/v1/analytics*' => Http::response(zernioAnalyticsList([
            zernioAnalyticsRow('ext-1', '7400000000000000001', '2026-10-01T09:00:00Z'),
            zernioAnalyticsRow('ext-2', '7400000000000000002', '2026-03-15T18:30:00Z', 'carousel'),
            // A Zernio upload TikTok never confirmed: no video id, left out.
            [...zernioAnalyticsRow('ext-3', '0', '2026-02-01T10:00:00Z'), 'platformPostUrl' => null, 'platforms' => [['platform' => 'tiktok', 'platformPostId' => null, 'syncStatus' => 'unavailable']]],
        ])),
        '*' => Http::response([], 500),
    ]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $this->account->id,
        'collector' => SyncCollector::PublicationBackfill,
        'checkpoint' => ['cursor' => null, 'revision' => 0],
        'target_since' => CarbonImmutable::now('UTC')->subDays(365),
    ]);

    app()->call([new BackfillAccountPublications($this->account->id, $state->id), 'handle']);

    $publications = AnalyticsPublication::query()->where('social_account_id', $this->account->id)->get()->keyBy('remote_id');

    expect($publications->keys()->sort()->values()->all())->toBe(['7400000000000000001', '7400000000000000002'])
        ->and($publications['7400000000000000001']->permalink)->toBe('https://www.tiktok.com/@marmaraai/video/7400000000000000001')
        ->and($publications['7400000000000000002']->content_type)->toBe(PublicationContentType::Carousel)
        ->and(data_get($publications['7400000000000000001']->preview_metadata, 'thumbnail_url'))->toBe('https://storage.zernio.test/covers/ext-1.jpg')
        ->and($state->fresh()->status)->toBe(SyncStatus::Complete);

    $imported = Post::query()->imported()->get()->keyBy('platform_post_id');

    expect($imported)->toHaveCount(2)
        ->and($imported['7400000000000000001']->origin)->toBe(Origin::Network)
        ->and($imported['7400000000000000001']->content)->toBe('Caption ext-1')
        ->and($imported['7400000000000000002']->content_type)->toBe(ContentType::TikTokPhoto);

    Queue::assertPushed(ImportExternalPostMedia::class, 2);
    Queue::assertPushed(CollectPublicationMetrics::class, 2);
    Queue::assertNotPushed(BackfillAccountPublications::class);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/analytics?')
        && $request['platform'] === 'tiktok'
        && $request['accountId'] === $this->zernioAccountId
        && $request['source'] === 'all'
        && $request['fromDate'] === '2025-11-14'
        && $request->hasHeader('Authorization', 'Bearer sk_test_key'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'tiktokapis.com'));
});

test('a history page with more pages queues the next page with Zernio page number as cursor', function () {
    Http::fake([
        'zernio.com/api/v1/analytics*' => Http::response(zernioAnalyticsList([
            zernioAnalyticsRow('ext-1', '7400000000000000001', '2026-10-01T09:00:00Z'),
        ], 1, 3)),
    ]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $this->account->id,
        'checkpoint' => ['cursor' => null, 'revision' => 0],
    ]);

    app()->call([new BackfillAccountPublications($this->account->id, $state->id), 'handle']);

    expect($state->fresh()->status)->toBe(SyncStatus::Running)
        ->and(data_get($state->fresh()->checkpoint, 'cursor'))->toBe('2');
    Queue::assertPushed(BackfillAccountPublications::class);
});

test('the metrics read maps Zernio TikTok metrics and reuses the ones the history page read', function () {
    Http::fake([
        'zernio.com/api/v1/analytics*' => Http::response(zernioAnalyticsList([
            zernioAnalyticsRow('ext-1', '7400000000000000001', '2026-11-10T09:00:00Z', analytics: [
                'views' => 1200, 'likes' => 80, 'comments' => 5, 'shares' => 3,
                'profileViews' => 7, 'igReelsAvgWatchTime' => 4300, 'igReelsVideoViewTotalTime' => 5160000,
            ]),
        ])),
    ]);
    $page = app(PublicationHistoryCollectorFactory::class)->for($this->account)
        ->page($this->account, null, CarbonImmutable::now('UTC')->subDays(30));
    $publication = zernioTikTokPublication($this->account, $page->publications[0]->providerPostId);

    $observation = app(PublicationMetricsCollectorFactory::class)->for(Platform::TikTok)
        ->collect($publication, CarbonImmutable::now('UTC')->startOfDay());
    $metrics = collect($observation->metrics)->mapWithKeys(fn ($metric): array => [$metric->key->value => $metric->value])->all();

    expect($metrics)->toMatchArray([
        MetricKey::Views->value => 1200,
        MetricKey::Reactions->value => 80,
        MetricKey::Comments->value => 5,
        MetricKey::Shares->value => 3,
        MetricKey::ProfileVisits->value => 7,
        MetricKey::AverageWatchTimeMilliseconds->value => 4300,
        MetricKey::WatchTimeMilliseconds->value => 5160000,
        MetricKey::Engagements->value => 88,
    ])->and($metrics)->not->toHaveKeys([MetricKey::Impressions->value, MetricKey::Reach->value, MetricKey::Saves->value]);

    Http::assertSentCount(1);
});

test('the metrics job reads one post from Zernio by its TikTok video id without the video.list scope', function () {
    Http::fake([
        'zernio.com/api/v1/analytics*' => Http::response([
            'postId' => 'ext-9',
            'analytics' => ['views' => 1, 'likes' => 1, 'comments' => 1, 'shares' => 1],
            'platformAnalytics' => [[
                'platform' => 'tiktok',
                'platformPostId' => '7400000000000000009',
                'accountId' => $this->zernioAccountId,
                'analytics' => ['views' => 640, 'likes' => 31, 'comments' => 2, 'shares' => 1, 'impressions' => 0],
                'syncStatus' => 'synced',
            ]],
            'syncStatus' => 'synced',
        ]),
    ]);
    $publication = zernioTikTokPublication($this->account, '7400000000000000009');

    app()->call([new CollectPublicationMetrics($publication->id, CarbonImmutable::now('UTC')->toDateString(), true), 'handle']);

    $snapshot = AnalyticsPublicationDailySnapshot::query()->where('publication_id', $publication->id)->sole();

    expect($snapshot->views_count)->toBe(640)
        ->and($snapshot->reactions_count)->toBe(31)
        ->and($snapshot->comments_count)->toBe(2)
        ->and($snapshot->shares_count)->toBe(1)
        ->and($snapshot->engagement_count)->toBe(34)
        ->and($snapshot->impressions_count)->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request['postId'] === '7400000000000000009'
        && $request['accountId'] === $this->zernioAccountId);
});

test('a post Zernio published before TikTok gave its video id is read by its Zernio post id', function () {
    Http::fake([
        'zernio.com/api/v1/analytics*' => Http::response([
            'analytics' => ['views' => 10, 'likes' => 2, 'comments' => 0, 'shares' => 0],
            'platformAnalytics' => [],
        ]),
    ]);
    $publication = zernioTikTokPublication($this->account, 'zernio:6a0f6d2e520992756d96bb6c');

    $observation = app(TikTokPublicationMetricsCollector::class)->collect($publication, CarbonImmutable::now('UTC')->startOfDay());

    expect(collect($observation->metrics)->firstWhere('key', MetricKey::Views)->value)->toBe(10);
    Http::assertSent(fn (Request $request): bool => $request['postId'] === '6a0f6d2e520992756d96bb6c');
});

test('the daily follower snapshot comes from Zernio without the user.info.stats scope', function () {
    Http::fake([
        'zernio.com/api/v1/accounts/follower-stats*' => Http::response([
            'accounts' => [['_id' => $this->zernioAccountId, 'platform' => 'tiktok', 'currentFollowers' => 1532]],
            'stats' => [$this->zernioAccountId => [['date' => '2026-11-13', 'followers' => 1530], ['date' => '2026-11-14', 'followers' => 1532]]],
            'granularity' => 'daily',
        ]),
    ]);

    app()->call([new CollectAccountDailySnapshot($this->account->id, '2026-11-14'), 'handle']);

    expect(AnalyticsAccountDailySnapshot::query()->where('social_account_id', $this->account->id)->sole()->followers_count)->toBe(1532);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/accounts/follower-stats')
        && $request['accountIds'] === $this->zernioAccountId);
});

test('the native scope gates do not block a Zernio-mapped account', function () {
    Http::fake([
        'zernio.com/api/v1/analytics*' => Http::response(zernioAnalyticsList([])),
        'zernio.com/api/v1/accounts/follower-stats*' => Http::response(['accounts' => [['_id' => $this->zernioAccountId, 'currentFollowers' => 5]], 'stats' => []]),
    ]);

    expect(app(PublicationHistoryCollectorFactory::class)->for($this->account))->toBeInstanceOf(ZernioTikTokPublicationCollector::class)
        ->and(app(PublicationMetricsCollectorFactory::class)->for(Platform::TikTok))->toBeInstanceOf(ZernioTikTokPublicationMetricsCollector::class)
        ->and(app(FollowerCollectorFactory::class)->for(Platform::TikTok))->toBeInstanceOf(ZernioTikTokFollowerCollector::class);

    $page = app(TikTokPublicationCollector::class)->page($this->account, null, CarbonImmutable::now('UTC')->subDays(30));
    $followers = app(TikTokFollowerCollector::class)->collect($this->account, CarbonImmutable::now('UTC')->startOfDay());

    expect($page->providerLimited)->toBeFalse()
        ->and($page->providerExhausted)->toBeTrue()
        ->and($followers->followers)->toBe(5)
        ->and(zernioRequested('/analytics'))->toBeTrue();
});

test('an unmapped TikTok account keeps the native collectors and their scope gates', function () {
    Http::fake();
    $other = SocialAccount::factory()->tiktok()->create([
        'username' => 'someoneelse',
        'scopes' => ['user.info.basic', 'video.publish'],
    ]);

    $page = app(TikTokPublicationCollector::class)->page($other, null, CarbonImmutable::now('UTC')->subDays(30));

    expect($page->providerLimited)->toBeTrue()
        ->and(fn () => app(TikTokFollowerCollector::class)->collect($other, CarbonImmutable::now('UTC')))
        ->toThrow(AnalyticsCollectionException::class, 'account grant lacks the user.info.stats scope');
    Http::assertNothingSent();
});

test('a Zernio 429 is retried at its Retry-After as rate limited', function () {
    Http::fake([
        'zernio.com/api/v1/analytics*' => Http::response([
            'error' => 'Rate limit exceeded',
            'details' => ['currentCount' => 301, 'limit' => 300, 'retryAfterSeconds' => 12, 'window' => '30s', 'scope' => 'post-analytics'],
        ], 429),
    ]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $this->account->id,
        'checkpoint' => ['cursor' => null, 'revision' => 0],
    ]);

    app()->call([new BackfillAccountPublications($this->account->id, $state->id), 'handle']);

    expect($state->fresh()->status)->toBe(SyncStatus::Running)
        ->and($state->fresh()->last_error_category)->toBe('rate_limited');
    Queue::assertPushed(BackfillAccountPublications::class, fn (BackfillAccountPublications $job): bool => $job->transientRetries === 1);

    try {
        app(TikTokPublicationMetricsCollector::class)->collect(
            zernioTikTokPublication($this->account, '7400000000000000001'),
            CarbonImmutable::now('UTC')->startOfDay(),
        );
        $this->fail('Expected a rate limited exception.');
    } catch (AnalyticsCollectionException $exception) {
        expect($exception->category)->toBe('rate_limited')
            ->and($exception->retryAt?->toIso8601String())->toBe(CarbonImmutable::now('UTC')->addSeconds(12)->toIso8601String());
    }
});

test('a Retry-After header wins over the body', function () {
    Http::fake([
        'zernio.com/api/v1/analytics*' => Http::response(['error' => 'Rate limit exceeded'], 429, ['Retry-After' => '40']),
    ]);

    expect(fn () => app(TikTokPublicationCollector::class)->page($this->account, null, CarbonImmutable::now('UTC')->subDays(3)))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('rate_limited')
            ->and($exception->retryAt?->toIso8601String())->toBe(CarbonImmutable::now('UTC')->addSeconds(40)->toIso8601String()));
});

test('with the module disabled TryPost resolves its own TikTok collectors', function () {
    config()->set('marmara-zernio.enabled', false);
    Http::fake();

    expect(app(TikTokPublicationCollector::class))->not->toBeInstanceOf(ZernioTikTokPublicationCollector::class)
        ->and(app(TikTokPublicationMetricsCollector::class))->not->toBeInstanceOf(ZernioTikTokPublicationMetricsCollector::class)
        ->and(app(TikTokFollowerCollector::class))->not->toBeInstanceOf(ZernioTikTokFollowerCollector::class);

    $page = app(PublicationHistoryCollectorFactory::class)->for($this->account)
        ->page($this->account, null, CarbonImmutable::now('UTC')->subDays(30));

    expect($page->providerLimited)->toBeTrue()
        ->and(fn () => app(FollowerCollectorFactory::class)->for(Platform::TikTok)->collect($this->account, CarbonImmutable::now('UTC')))
        ->toThrow(AnalyticsCollectionException::class);
    Http::assertNothingSent();
});

test('ZERNIO_ANALYTICS_ENABLED=false keeps publishing through Zernio but analytics native', function () {
    config()->set('marmara-zernio.analytics.enabled', false);

    expect(app(TikTokPublicationCollector::class))->not->toBeInstanceOf(ZernioTikTokPublicationCollector::class)
        ->and(app(TikTokFollowerCollector::class))->not->toBeInstanceOf(ZernioTikTokFollowerCollector::class);
});

test('the backfill command restarts a provider limited backfill, imports the history and follower days', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/accounts/follower-stats')) {
            return Http::response([
                'accounts' => [['_id' => $this->zernioAccountId, 'currentFollowers' => 1532]],
                'stats' => [$this->zernioAccountId => [
                    ['date' => '2026-11-12', 'followers' => 1500],
                    ['date' => '2026-11-13', 'followers' => 1530],
                ]],
            ]);
        }

        return (int) $request['page'] === 1
            ? Http::response(zernioAnalyticsList([zernioAnalyticsRow('ext-1', '7400000000000000001', '2026-10-01T09:00:00Z')], 1, 2))
            : Http::response(zernioAnalyticsList([zernioAnalyticsRow('ext-2', '7400000000000000002', '2026-01-20T09:00:00Z')], 2, 2));
    });
    // What the native collector left behind without video.list.
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $this->account->id,
        'status' => SyncStatus::ProviderLimited,
        'checkpoint' => ['cursor' => null, 'revision' => 3],
        'last_error_category' => 'provider_limited',
    ]);

    $this->artisan('marmara:zernio:analytics-backfill', ['--days' => 365])
        ->expectsOutputToContain('6af764170fb0c006b7a777e1')
        ->assertSuccessful();

    expect($state->fresh()->status)->toBe(SyncStatus::Complete)
        ->and($state->fresh()->oldest_reached_at->toDateString())->toBe('2026-01-20')
        ->and(AnalyticsPublication::query()->where('social_account_id', $this->account->id)->count())->toBe(2)
        ->and(Post::query()->imported()->count())->toBe(2)
        ->and(AnalyticsAccountDailySnapshot::query()->where('social_account_id', $this->account->id)->orderBy('date')->pluck('followers_count')->all())->toBe([1500, 1530])
        ->and(AnalyticsSyncState::query()->where('social_account_id', $this->account->id)->forCollector(SyncCollector::PublicationDiscovery)->exists())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/analytics?') && (int) $request['page'] === 2);
});

test('the backfill command imports only up to its limit into Sent', function () {
    Http::fake([
        'zernio.com/api/v1/accounts/follower-stats*' => Http::response(['accounts' => [], 'stats' => []]),
        'zernio.com/api/v1/analytics*' => Http::response(zernioAnalyticsList([
            zernioAnalyticsRow('ext-1', '7400000000000000001', '2026-10-01T09:00:00Z'),
            zernioAnalyticsRow('ext-2', '7400000000000000002', '2026-09-01T09:00:00Z'),
            zernioAnalyticsRow('ext-3', '7400000000000000003', '2026-08-01T09:00:00Z'),
        ])),
    ]);

    $this->artisan('marmara:zernio:analytics-backfill', ['--import-limit' => 1])->assertSuccessful();

    expect(Post::query()->imported()->pluck('platform_post_id')->all())->toBe(['7400000000000000001'])
        ->and(config('trypost.external_posts.import_limit'))->not->toBe(1);
});

test('the backfill command refuses to run with the module disabled', function () {
    config()->set('marmara-zernio.enabled', false);
    Http::fake();

    $this->artisan('marmara:zernio:analytics-backfill')->assertFailed();
    Http::assertNothingSent();
});
