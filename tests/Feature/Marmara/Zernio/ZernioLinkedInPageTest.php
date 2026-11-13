<?php

declare(strict_types=1);

use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\SyncStatus;
use App\Enums\Post\Origin;
use App\Enums\Post\PublishStatus as PlatformStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Jobs\Analytics\BackfillAccountPublications;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Analytics\CollectPublicationMetrics;
use App\Jobs\PublishToSocialPlatform;
use App\Marmara\Zernio\Analytics\Collectors\ZernioLinkedInPagePublicationCollector;
use App\Marmara\Zernio\Models\ZernioPublication;
use App\Marmara\Zernio\ZernioConfig;
use App\Marmara\Zernio\ZernioLinkedInPagePublisher;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Analytics\Collectors\Metrics\PublicationMetricsCollectorFactory;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use App\Services\Social\LinkedInPagePublisher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

const ZERNIO_LI_API = 'https://zernio.com/api/v1';
const ZERNIO_LI_ACCOUNT = '7b1c2d3e4f5a6b7c8d9e0f1a';
const ZERNIO_LI_POST = '7c0f6d2e520992756d96bb6d';
const ZERNIO_LI_URN = 'urn:li:share:7390000000000000001';

beforeEach(function () {
    Event::fake();
    Mail::fake();
    Queue::fake();
    CarbonImmutable::setTestNow('2026-11-14 12:00:00 UTC');

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->page = SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'li-member-1',
        'username' => 'marmaraai',
        'display_name' => 'Marmara Yapay Zeka Kulübü',
        'meta' => ['organization_id' => '104857600'],
        'token_expires_at' => now()->addDay(),
    ]);

    config()->set('marmara-zernio', [
        'enabled' => true,
        'api_key' => 'sk_test_key',
        'base_url' => ZERNIO_LI_API,
        'timeout' => 30,
        'accounts' => [
            'tiktok' => ['marmaraai' => '6af764170fb0c006b7a777e1'],
            'linkedin-page' => [(string) $this->page->id => ZERNIO_LI_ACCOUNT],
        ],
        'tiktok' => ['accounts' => [], 'media_mode' => 'url', 'status_poll_seconds' => 60, 'status_window_minutes' => 60],
        'analytics' => ['enabled' => true, 'page_size' => 50, 'cache_minutes' => 360, 'import_limit' => 500],
    ]);
});

/**
 * @param  array<string, mixed>  $meta
 * @param  list<array<string, mixed>>  $media
 */
function zernioLinkedInPost(SocialAccount $account, User $user, array $meta = [], array $media = []): Post
{
    $post = Post::factory()->forAccount($account, ContentType::LinkedInPagePost)->scheduled()->create(['user_id' => $user->id]);

    $post->forceFill([
        'content' => 'Marmara AI kulübü yeni dönem duyurusu https://marmaraai.org',
        'scheduled_before_media_checks' => true,
        'meta' => $meta,
        'media' => $media,
    ])->save();

    return $post->fresh();
}

/**
 * @param  array<string, mixed>  $target
 * @return array<string, mixed>
 */
function zernioLinkedInPostBody(string $status, array $target = []): array
{
    return ['post' => [
        '_id' => ZERNIO_LI_POST,
        'status' => $status,
        'platforms' => [[
            'platform' => 'linkedin',
            'accountId' => ZERNIO_LI_ACCOUNT,
            'status' => $status,
            ...$target,
        ]],
    ]];
}

/**
 * @return array<string, mixed>
 */
function zernioLinkedInFakes(array $target = ['platformPostId' => ZERNIO_LI_URN, 'platformPostUrl' => 'https://www.linkedin.com/feed/update/'.ZERNIO_LI_URN]): array
{
    return [
        ZERNIO_LI_API.'/accounts*' => Http::response(['accounts' => [
            ['_id' => ZERNIO_LI_ACCOUNT, 'platform' => 'linkedin', 'username' => 'marmaraai', 'status' => 'connected', 'accountType' => 'organization'],
        ]]),
        ZERNIO_LI_API.'/posts' => Http::response(zernioLinkedInPostBody('published', $target), 201),
    ];
}

/**
 * @return array<string, mixed>
 */
function zernioLinkedInMedia(string $id, string $type, string $mime, string $file): array
{
    return ['id' => $id, 'type' => $type, 'path' => "medias/{$file}", 'url' => "https://example.com/medias/{$file}", 'mime_type' => $mime, 'original_filename' => $file];
}

test('the container hands out the Zernio LinkedIn page publisher only while the module is enabled', function () {
    expect(app(LinkedInPagePublisher::class))->toBeInstanceOf(ZernioLinkedInPagePublisher::class);

    config()->set('marmara-zernio.enabled', false);

    expect(app(LinkedInPagePublisher::class))->not->toBeInstanceOf(ZernioLinkedInPagePublisher::class);
});

test('a mapped LinkedIn page text post is published through Zernio, even with the native connection lost', function () {
    $this->page->update(['status' => AccountStatus::Disconnected, 'scopes' => []]);
    $post = zernioLinkedInPost($this->page, $this->user, ['thread_replies' => [['text' => 'Başvuru linki yorumda', 'media' => []]]]);
    Http::fake(zernioLinkedInFakes());

    (new PublishToSocialPlatform($post))->handle();

    $post->refresh();
    expect($post->publish_status)->toBe(PlatformStatus::Published)
        ->and($post->platform_post_id)->toBe(ZERNIO_LI_URN)
        ->and($post->platform_url)->toBe('https://www.linkedin.com/feed/update/'.ZERNIO_LI_URN)
        ->and(ZernioPublication::query()->where('post_id', $post->id)->sole()->last_status)->toBe(ZernioPublication::STATUS_PUBLISHED);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === ZERNIO_LI_API.'/posts'
        && filled($request->header('Idempotency-Key')[0] ?? null)
        && $request['publishNow'] === true
        && $request['content'] === 'Marmara AI kulübü yeni dönem duyurusu https://marmaraai.org'
        && ! array_key_exists('mediaItems', $request->data())
        && $request['platforms'] === [[
            'platform' => 'linkedin',
            'accountId' => ZERNIO_LI_ACCOUNT,
            'platformSpecificData' => [
                'organizationUrn' => 'urn:li:organization:104857600',
                'firstComment' => 'Başvuru linki yorumda',
            ],
        ]]
        && str_starts_with($request['tags'][0], 'trypost-'));
    Http::assertSent(fn (Request $request): bool => $request->url() === ZERNIO_LI_API.'/accounts?platform=linkedin');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.linkedin.com'));
});

test('a linkedin-page mapping that points at a personal profile is refused before anything is sent', function () {
    $post = zernioLinkedInPost($this->page, $this->user);
    Http::fake([
        ZERNIO_LI_API.'/accounts*' => Http::response(['accounts' => [
            ['_id' => ZERNIO_LI_ACCOUNT, 'platform' => 'linkedin', 'username' => 'a-member', 'status' => 'connected', 'accountType' => 'personal'],
        ]]),
        ZERNIO_LI_API.'/posts' => Http::response(zernioLinkedInPostBody('published', ['platformPostId' => ZERNIO_LI_URN]), 201),
    ]);

    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Failed)
        ->and($post->fresh()->error_message)->toContain('is not a LinkedIn company page');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === ZERNIO_LI_API.'/posts');
});

test('a text post with the link preview turned off asks Zernio not to add one', function () {
    $post = zernioLinkedInPost($this->page, $this->user, ['link_preview' => false]);
    Http::fake(zernioLinkedInFakes());

    (new PublishToSocialPlatform($post))->handle();

    Http::assertSent(fn (Request $request): bool => $request->url() === ZERNIO_LI_API.'/posts'
        && $request['platforms'][0]['platformSpecificData']['disableLinkPreview'] === true);
});

test('one image, several images and a video go to Zernio as LinkedIn media', function (array $media, array $expected) {
    $post = zernioLinkedInPost($this->page, $this->user, [], $media);
    Http::fake(zernioLinkedInFakes());

    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Published);
    Http::assertSent(fn (Request $request): bool => $request->url() === ZERNIO_LI_API.'/posts'
        && $request['mediaItems'] === $expected);
})->with([
    'image' => [
        [zernioLinkedInMedia('i1', 'image', 'image/jpeg', 'a.jpg')],
        [['type' => 'image', 'url' => 'https://example.com/medias/a.jpg']],
    ],
    'multi image' => [
        [zernioLinkedInMedia('i1', 'image', 'image/jpeg', 'a.jpg'), zernioLinkedInMedia('i2', 'image', 'image/png', 'b.png')],
        [['type' => 'image', 'url' => 'https://example.com/medias/a.jpg'], ['type' => 'image', 'url' => 'https://example.com/medias/b.png']],
    ],
    'video' => [
        [zernioLinkedInMedia('v1', 'video', 'video/mp4', 'club.mp4')],
        [['type' => 'video', 'url' => 'https://example.com/medias/club.mp4']],
    ],
]);

test('a document post sends the PDF with its title', function () {
    $post = zernioLinkedInPost($this->page, $this->user, ['document_title' => 'Etkinlik takvimi'], [
        zernioLinkedInMedia('d1', 'document', 'application/pdf', 'takvim.pdf'),
    ]);
    Http::fake(zernioLinkedInFakes());

    (new PublishToSocialPlatform($post))->handle();

    Http::assertSent(fn (Request $request): bool => $request->url() === ZERNIO_LI_API.'/posts'
        && $request['mediaItems'] === [['type' => 'document', 'url' => 'https://example.com/medias/takvim.pdf', 'title' => 'Etkinlik takvimi']]
        && $request['platforms'][0]['platformSpecificData']['documentTitle'] === 'Etkinlik takvimi');
});

test('more than one thread comment is refused before anything is sent', function () {
    $post = zernioLinkedInPost($this->page, $this->user, ['thread_replies' => [['text' => 'bir', 'media' => []], ['text' => 'iki', 'media' => []]]]);
    Http::fake(zernioLinkedInFakes());

    (new PublishToSocialPlatform($post))->handle();

    // TryPost's own LinkedIn limit (one comment) refuses it first, with its own message.
    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Failed)
        ->and(ZernioPublication::query()->where('post_id', $post->id)->exists())->toBeFalse();
    Http::assertNotSent(fn (Request $request): bool => $request->url() === ZERNIO_LI_API.'/posts');
});

test('a thread comment with media is refused by the Zernio channel before anything is sent', function () {
    $post = zernioLinkedInPost($this->page, $this->user, ['thread_replies' => [[
        'text' => 'bir',
        'media' => [zernioLinkedInMedia('c1', 'image', 'image/jpeg', 'c.jpg')],
    ]]]);
    Http::fake(zernioLinkedInFakes());

    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Failed)
        ->and($post->fresh()->error_message)->toContain('LinkedIn (via Zernio): only one text comment');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === ZERNIO_LI_API.'/posts');
});

test('a LinkedIn target Zernio is still publishing is checked again later', function () {
    $post = zernioLinkedInPost($this->page, $this->user);
    Http::fake([
        ...zernioLinkedInFakes(),
        ZERNIO_LI_API.'/posts' => Http::response(zernioLinkedInPostBody('publishing'), 201),
    ]);

    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Retrying)
        ->and(ZernioPublication::query()->where('post_id', $post->id)->sole()->zernio_post_id)->toBe(ZERNIO_LI_POST);
});

test('an unmapped LinkedIn page keeps publishing natively', function () {
    $other = SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'someotherpage',
        'meta' => ['organization_id' => '55'],
        'token_expires_at' => now()->addDays(30),
    ]);
    $post = zernioLinkedInPost($other, $this->user);
    Http::fake([
        'zernio.com/*' => Http::response([], 500),
        '*' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:1']),
    ]);

    (new PublishToSocialPlatform($post))->handle();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'linkedin.com'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'zernio.com'));
    expect(ZernioPublication::query()->where('post_id', $post->id)->exists())->toBeFalse();
});

test('ZERNIO_ACCOUNTS entries are parsed per platform and the TikTok variables still count', function () {
    expect(ZernioConfig::parseAccounts(' tiktok:marmaraai=6af764170fb0c006b7a777e1, LinkedIn-Page:club-page = 7b1c2d3e4f5a6b7c8d9e0f1a ,broken,:x=y,tiktok:=z'))
        ->toBe([
            'tiktok' => ['marmaraai' => '6af764170fb0c006b7a777e1'],
            'linkedin-page' => ['club-page' => '7b1c2d3e4f5a6b7c8d9e0f1a'],
        ])
        ->and(ZernioConfig::parsePairs('a=1, b = 2,c'))->toBe(['a' => '1', 'b' => '2']);

    config()->set('marmara-zernio.tiktok.accounts', ['legacy' => '6af764170fb0c006b7a777e2']);
    $config = app(ZernioConfig::class);

    expect($config->accounts(Platform::TikTok))->toBe([
        'marmaraai' => '6af764170fb0c006b7a777e1',
        'legacy' => '6af764170fb0c006b7a777e2',
    ])->and($config->accounts(Platform::LinkedInPage))->toBe([(string) $this->page->id => ZERNIO_LI_ACCOUNT]);
});

test('a mapping only routes its own platform, and removing it moves the channel back', function () {
    $config = app(ZernioConfig::class);
    $tiktok = SocialAccount::factory()->tiktok()->create(['username' => 'marmaraai']);
    $personal = SocialAccount::factory()->linkedin()->create(['username' => 'marmaraai']);

    expect($config->zernioAccountFor($this->page))->toBe(ZERNIO_LI_ACCOUNT)
        ->and($config->zernioAccountFor($tiktok))->toBe('6af764170fb0c006b7a777e1')
        ->and($config->zernioAccountFor($personal))->toBeNull();

    config()->set('marmara-zernio.accounts.linkedin-page', []);

    expect($config->zernioAccountFor($this->page))->toBeNull()
        ->and(Platform::LinkedInPage->isIncludedInAnalytics())->toBeFalse();
});

test('an unsupported platform in ZERNIO_ACCOUNTS is reported', function () {
    config()->set('marmara-zernio.accounts.linkedin', ['me' => '7b1c2d3e4f5a6b7c8d9e0f1a']);

    expect(app(ZernioConfig::class)->problems())
        ->toContain('ZERNIO_ACCOUNTS: linkedin is not supported (use tiktok, linkedin-page).');
});

test('LinkedIn pages are in analytics only while one is mapped and Zernio analytics are on', function () {
    expect(Platform::LinkedInPage->isIncludedInAnalytics())->toBeTrue()
        ->and(Platform::LinkedIn->isIncludedInAnalytics())->toBeFalse()
        ->and(Platform::analyticsValues())->toContain('linkedin-page')
        ->and(SocialAccount::query()->includedInAnalytics()->whereKey($this->page->id)->exists())->toBeTrue();

    config()->set('marmara-zernio.analytics.enabled', false);

    expect(Platform::LinkedInPage->isIncludedInAnalytics())->toBeFalse();

    config()->set('marmara-zernio.analytics.enabled', true);
    config()->set('marmara-zernio.enabled', false);

    expect(Platform::LinkedInPage->isIncludedInAnalytics())->toBeFalse()
        ->and(fn () => app(PublicationHistoryCollectorFactory::class)->for($this->page))
        ->toThrow(AnalyticsCollectionException::class);
});

test('the LinkedIn page history, post metrics and followers come from Zernio', function () {
    Http::fake([
        ZERNIO_LI_API.'/analytics*' => Http::response([
            'posts' => [[
                '_id' => 'ext-li-1',
                'content' => 'Kulüp tanıtım günü',
                'publishedAt' => '2026-09-10T08:00:00Z',
                'mediaType' => 'image',
                'isExternal' => true,
                'thumbnailUrl' => 'https://storage.zernio.test/li-1.jpg',
                'platformPostUrl' => 'https://www.linkedin.com/feed/update/'.ZERNIO_LI_URN,
                'platforms' => [[
                    'platform' => 'linkedin',
                    'platformPostId' => ZERNIO_LI_URN,
                    'accountId' => ZERNIO_LI_ACCOUNT,
                    'analytics' => ['impressions' => 1800, 'reach' => 1200, 'likes' => 64, 'comments' => 7, 'shares' => 3, 'clicks' => 41, 'views' => 0, 'saves' => 0],
                    'platformPostUrl' => 'https://www.linkedin.com/feed/update/'.ZERNIO_LI_URN,
                ]],
            ]],
            'pagination' => ['page' => 1, 'limit' => 50, 'total' => 1, 'pages' => 1],
        ]),
        ZERNIO_LI_API.'/accounts/follower-stats*' => Http::response([
            'accounts' => [['_id' => ZERNIO_LI_ACCOUNT, 'platform' => 'linkedin', 'currentFollowers' => 980]],
            'stats' => [ZERNIO_LI_ACCOUNT => [['date' => '2026-11-14', 'followers' => 980]]],
        ]),
    ]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $this->page->id,
        'checkpoint' => ['cursor' => null, 'revision' => 0],
        'target_since' => CarbonImmutable::now('UTC')->subDays(365),
    ]);

    expect(app(PublicationHistoryCollectorFactory::class)->for($this->page))->toBeInstanceOf(ZernioLinkedInPagePublicationCollector::class);

    app()->call([new BackfillAccountPublications($this->page->id, $state->id), 'handle']);

    $publication = AnalyticsPublication::query()->where('social_account_id', $this->page->id)->sole();

    expect($publication->remote_id)->toBe(ZERNIO_LI_URN)
        ->and($publication->content_type)->toBe(PublicationContentType::Image)
        ->and($state->fresh()->status)->toBe(SyncStatus::Complete)
        ->and(Post::query()->imported()->sole())
        ->origin->toBe(Origin::Network)
        ->platform_post_id->toBe(ZERNIO_LI_URN);
    Queue::assertPushed(CollectPublicationMetrics::class);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/analytics?') && $request['platform'] === 'linkedin');

    $observation = app(PublicationMetricsCollectorFactory::class)->for(Platform::LinkedInPage)
        ->collect($publication->setRelation('socialAccount', $this->page), CarbonImmutable::now('UTC')->startOfDay());
    $metrics = collect($observation->metrics)->mapWithKeys(fn ($metric): array => [$metric->key->value => $metric->value])->all();

    expect($metrics)->toMatchArray([
        MetricKey::Impressions->value => 1800,
        MetricKey::Reach->value => 1200,
        MetricKey::Reactions->value => 64,
        MetricKey::Comments->value => 7,
        MetricKey::Shares->value => 3,
        MetricKey::Clicks->value => 41,
        MetricKey::Engagements->value => 115,
    ])->and($metrics)->not->toHaveKeys([MetricKey::Views->value, MetricKey::Saves->value]);

    app()->call([new CollectAccountDailySnapshot($this->page->id, '2026-11-14'), 'handle']);

    expect(AnalyticsAccountDailySnapshot::query()->where('social_account_id', $this->page->id)->sole()->followers_count)->toBe(980);
});
