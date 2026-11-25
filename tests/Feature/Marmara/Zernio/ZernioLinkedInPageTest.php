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
use App\Marmara\Zernio\Publishing\ZernioLinkedInPageChannel;
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

test('company mentions go to Zernio as organization URNs, resolved with the page\'s native token', function () {
    config()->set('marmara-linkedin.api', 'https://api.linkedin.com');
    $this->page->update(['access_token' => 'li-native-token', 'token_expires_at' => now()->addDays(60)]);
    $post = zernioLinkedInPost($this->page, $this->user);
    $post->forceFill(['content' => 'Teşekkürler @[Akbank](https://www.linkedin.com/company/akbank) ve @[Marmara AI](urn:li:organization:789) (sponsor)'])->save();
    Http::fake([
        'https://api.linkedin.com/rest/organizations*' => Http::response(['elements' => [['id' => 123, 'localizedName' => 'Akbank T.A.Ş.', 'vanityName' => 'akbank']]]),
        ...zernioLinkedInFakes(),
    ]);

    (new PublishToSocialPlatform($post->fresh()))->handle();

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Published);
    Http::assertSent(fn (Request $request): bool => $request->url() === ZERNIO_LI_API.'/posts'
        && $request['content'] === 'Teşekkürler @[Akbank T.A.Ş.](urn:li:organization:123) ve @[Marmara AI](urn:li:organization:789) (sponsor)');
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.linkedin.com/rest/organizations')
        && $request->hasHeader('Authorization', 'Bearer li-native-token'));
    // Resolved natively: Zernio's resolver is not asked.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/linkedin-mentions'));
});

function zernioMentionUrl(string $account = ZERNIO_LI_ACCOUNT): string
{
    return ZERNIO_LI_API.'/accounts/'.$account.'/linkedin-mentions';
}

function zernioMentionSent(Request $request, string $account = ZERNIO_LI_ACCOUNT): bool
{
    return str_starts_with($request->url(), zernioMentionUrl($account).'?')
        && str_contains($request->url(), 'url='.rawurlencode('https://www.linkedin.com/company/akbank'));
}

test('a Zernio page without a native token resolves a vanity mention through Zernio, cached for the next posts', function () {
    config()->set('marmara-linkedin.api', 'https://api.linkedin.com');
    $this->page->update(['access_token' => 'zernio-managed:'.ZERNIO_LI_ACCOUNT, 'meta' => ['organization_id' => '104857600', 'managed_by' => 'zernio']]);
    Http::fake([
        // Before the generic `/accounts*` stub: the first matching stub wins.
        zernioMentionUrl().'*' => Http::response([
            'urn' => 'urn:li:organization:123',
            'type' => 'organization',
            'displayName' => 'Akbank T.A.Ş.',
            'vanityName' => 'akbank',
            'mentionFormat' => '@[Akbank T.A.Ş.](urn:li:organization:123)',
        ]),
        ...zernioLinkedInFakes(),
    ]);
    $post = zernioLinkedInPost($this->page, $this->user);
    $post->forceFill(['content' => 'Teşekkürler @[Akbank](https://www.linkedin.com/company/akbank) ve @[Marmara AI](urn:li:organization:789)'])->save();

    (new PublishToSocialPlatform($post->fresh()))->handle();

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Published);
    Http::assertSent(fn (Request $request): bool => $request->url() === ZERNIO_LI_API.'/posts'
        && $request['content'] === 'Teşekkürler @[Akbank T.A.Ş.](urn:li:organization:123) ve @[Marmara AI](urn:li:organization:789)');
    Http::assertSent(fn (Request $request): bool => zernioMentionSent($request)
        && $request->hasHeader('Authorization', 'Bearer sk_test_key'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.linkedin.com'));

    // The next post reads the shared cache: no second call.
    $next = zernioLinkedInPost($this->page, $this->user);
    $next->forceFill(['content' => 'Yine @[Akbank](https://www.linkedin.com/company/akbank)'])->save();

    expect(app(ZernioLinkedInPageChannel::class)->payload($next->fresh(), ZERNIO_LI_ACCOUNT, 'trypost-ref')['content'])
        ->toBe('Yine @[Akbank T.A.Ş.](urn:li:organization:123)');
    expect(collect(Http::recorded())->filter(fn (array $pair): bool => zernioMentionSent($pair[0]))->count())->toBe(1);
});

test('a Zernio page without a native token sends the plain name when Zernio cannot resolve, cached per Zernio account, until a native LinkedIn channel can resolve it', function () {
    config()->set('marmara-linkedin.api', 'https://api.linkedin.com');
    $this->page->update(['access_token' => 'zernio-managed:'.ZERNIO_LI_ACCOUNT, 'meta' => ['organization_id' => '104857600', 'managed_by' => 'zernio']]);
    $other = '8c2d3e4f5a6b7c8d9e0f1a2b';
    Http::fake([
        zernioMentionUrl().'*' => Http::response(['error' => 'Organization not found'], 404),
        zernioMentionUrl($other).'*' => Http::response(['error' => 'Organization not found'], 404),
        'https://api.linkedin.com/rest/organizations*' => Http::response(['elements' => [['id' => 123, 'localizedName' => 'Akbank', 'vanityName' => 'akbank']]]),
    ]);
    $post = zernioLinkedInPost($this->page, $this->user);
    $post->forceFill(['content' => 'Teşekkürler @[Akbank](https://www.linkedin.com/company/akbank) ve @[Marmara AI](urn:li:organization:789)'])->save();
    $channel = app(ZernioLinkedInPageChannel::class);

    expect($channel->payload($post->fresh(), ZERNIO_LI_ACCOUNT, 'trypost-ref')['content'])
        ->toBe('Teşekkürler Akbank ve @[Marmara AI](urn:li:organization:789)')
        // The miss is cached for this Zernio account: no second call.
        ->and($channel->payload($post->fresh(), ZERNIO_LI_ACCOUNT, 'trypost-ref')['content'])
        ->toBe('Teşekkürler Akbank ve @[Marmara AI](urn:li:organization:789)');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => zernioMentionSent($request));

    // Another Zernio account has its own miss cache.
    $channel->payload($post->fresh(), $other, 'trypost-ref');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => zernioMentionSent($request, $other));

    // A natively connected LinkedIn profile in the workspace lends its token.
    SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'member-native',
        'access_token' => 'li-profile-token',
        'token_expires_at' => now()->addDays(60),
    ]);

    expect($channel->payload($post->fresh(), ZERNIO_LI_ACCOUNT, 'trypost-ref')['content'])
        ->toBe('Teşekkürler @[Akbank](urn:li:organization:123) ve @[Marmara AI](urn:li:organization:789)');
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.linkedin.com/rest/organizations')
        && $request->hasHeader('Authorization', 'Bearer li-profile-token'));
    Http::assertSentCount(3);
});

test('a create whose answer was lost is resent byte for byte, although the mention resolves in between', function () {
    config()->set('marmara-linkedin.api', 'https://api.linkedin.com');
    $this->page->update(['access_token' => 'zernio-managed:'.ZERNIO_LI_ACCOUNT, 'meta' => ['organization_id' => '104857600', 'managed_by' => 'zernio']]);
    $sent = [];
    Http::fake([
        // Before the generic `/accounts*` stub: the first matching stub wins.
        zernioMentionUrl().'*' => Http::sequence()
            ->push(['error' => 'Organization not found'], 404)
            ->push(['urn' => 'urn:li:organization:123', 'type' => 'organization', 'displayName' => 'Akbank T.A.Ş.']),
        ...zernioLinkedInFakes(),
        // Replaces the `/posts` stub of zernioLinkedInFakes() in place: the first answer is lost.
        ZERNIO_LI_API.'/posts' => function (Request $request) use (&$sent) {
            $sent[] = ['body' => $request->body(), 'key' => $request->header('Idempotency-Key')[0] ?? null];

            return count($sent) === 1
                ? Http::failedConnection('cURL error 28: Operation timed out after 30000 milliseconds')($request)
                : Http::response(zernioLinkedInPostBody('published', ['platformPostId' => ZERNIO_LI_URN]), 201);
        },
    ]);
    $post = zernioLinkedInPost($this->page, $this->user);
    $post->forceFill(['content' => 'Teşekkürler @[Akbank](https://www.linkedin.com/company/akbank)'])->save();

    (new PublishToSocialPlatform($post->fresh()))->handle();

    $attempt = ZernioPublication::query()->where('post_id', $post->id)->first();
    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Retrying)
        ->and($attempt->last_status)->toBe(ZernioPublication::STATUS_UNKNOWN)
        ->and($attempt->request_body)->toBe($sent[0]['body'])
        ->and(json_decode($sent[0]['body'], true)['content'])->toBe('Teşekkürler Akbank')
        // Auth is a header: neither the Zernio key nor a LinkedIn token is in the body.
        ->and($attempt->request_body)->not->toContain('sk_test_key')
        ->and($attempt->request_body)->not->toContain('zernio-managed');

    // The miss expires and the company now resolves (and is cached): a payload built now differs.
    $this->travel(20)->minutes();
    expect(app(ZernioLinkedInPageChannel::class)->payload($post->fresh(), ZERNIO_LI_ACCOUNT, $attempt->reference())['content'])
        ->toBe('Teşekkürler @[Akbank T.A.Ş.](urn:li:organization:123)');

    (new PublishToSocialPlatform($post->fresh()))->handle();

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Published)
        ->and($sent)->toHaveCount(2)
        ->and($sent[1]['key'])->toBe($sent[0]['key'])
        ->and($sent[1]['key'])->toBe($attempt->idempotency_key)
        ->and($sent[1]['body'])->toBe($sent[0]['body'])
        ->and(ZernioPublication::query()->where('post_id', $post->id)->value('request_body'))->toBeNull();
    // The retry resolved nothing: one lookup for the first build, one for the payload above.
    expect(collect(Http::recorded())->filter(fn (array $pair): bool => zernioMentionSent($pair[0]))->count())->toBe(2);
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
