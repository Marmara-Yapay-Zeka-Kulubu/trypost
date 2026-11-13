<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Jobs\Analytics\BackfillAccountPublications;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Analytics\CollectPublicationMetrics;
use App\Jobs\RefreshSocialToken;
use App\Jobs\VerifyWorkspaceConnections;
use App\Marmara\Zernio\ZernioConnectionVerifier;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Analytics\Collectors\Metrics\TikTokPublicationMetricsCollector;
use App\Services\Social\ConnectionVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

const ZERNIO_EL_API = 'https://zernio.com/api/v1';
const ZERNIO_EL_TIKTOK = '6af764170fb0c006b7a777e1';
const ZERNIO_EL_LINKEDIN = '7b1c2d3e4f5a6b7c8d9e0f1a';

beforeEach(function () {
    Queue::fake();
    Mail::fake();
    CarbonImmutable::setTestNow('2026-11-14 12:00:00 UTC');

    config()->set('marmara-zernio', [
        'enabled' => true,
        'api_key' => 'sk_test_key',
        'base_url' => ZERNIO_EL_API,
        'timeout' => 30,
        'accounts' => ['tiktok' => ['marmaraai' => ZERNIO_EL_TIKTOK]],
        'tiktok' => ['accounts' => [], 'media_mode' => 'url', 'status_poll_seconds' => 60, 'status_window_minutes' => 60],
        'analytics' => ['enabled' => true, 'page_size' => 50, 'cache_minutes' => 360, 'import_limit' => 500],
    ]);

    // The native TikTok token died; the channel lives on through Zernio.
    $this->account = SocialAccount::factory()->tiktok()->create([
        'username' => 'marmaraai',
        'platform_user_id' => 'tt-marmara',
        'status' => AccountStatus::TokenExpired,
        'token_expires_at' => now()->subDay(),
    ]);
});

/**
 * @param  array<string, mixed>  $account
 * @return array<string, mixed>
 */
function zernioEligibilityAccounts(array $account = []): array
{
    return ['accounts' => [['_id' => ZERNIO_EL_TIKTOK, 'platform' => 'tiktok', 'username' => 'marmaraai', 'isActive' => true, ...$account]]];
}

function zernioEligibilityPublication(SocialAccount $account, string $remoteId): AnalyticsPublication
{
    return AnalyticsPublication::factory()->create([
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
}

test('a mapped channel whose native token expired is still measured while its Zernio account is healthy', function () {
    Http::fake([
        ZERNIO_EL_API.'/accounts/follower-stats*' => Http::response(['accounts' => [['_id' => ZERNIO_EL_TIKTOK, 'currentFollowers' => 1532]], 'stats' => []]),
        ZERNIO_EL_API.'/accounts*' => Http::response(zernioEligibilityAccounts()),
        ZERNIO_EL_API.'/analytics*' => Http::response([
            'platformAnalytics' => [['platform' => 'tiktok', 'accountId' => ZERNIO_EL_TIKTOK, 'syncStatus' => 'synced', 'analytics' => ['views' => 640, 'likes' => 31, 'comments' => 2, 'shares' => 1]]],
            'syncStatus' => 'synced',
        ]),
    ]);
    $publication = zernioEligibilityPublication($this->account, '7400000000000000009');

    app()->call([new CollectPublicationMetrics($publication->id, '2026-11-14', true), 'handle']);
    app()->call([new CollectAccountDailySnapshot($this->account->id, '2026-11-14'), 'handle']);

    expect(AnalyticsPublicationDailySnapshot::query()->where('publication_id', $publication->id)->sole()->views_count)->toBe(640)
        ->and(AnalyticsAccountDailySnapshot::query()->where('social_account_id', $this->account->id)->sole()->followers_count)->toBe(1532)
        ->and(SocialAccount::query()->connectedForAnalytics()->whereKey($this->account->id)->exists())->toBeTrue();
});

test('a mapped channel whose Zernio account needs reconnecting is not measured', function () {
    Http::fake([
        ZERNIO_EL_API.'/accounts*' => Http::response(zernioEligibilityAccounts(['needsReconnection' => true])),
        ZERNIO_EL_API.'/analytics*' => Http::response(['analytics' => ['views' => 1]]),
    ]);
    $publication = zernioEligibilityPublication($this->account, '7400000000000000009');

    app()->call([new CollectPublicationMetrics($publication->id, '2026-11-14', true), 'handle']);

    expect(AnalyticsPublicationDailySnapshot::query()->where('publication_id', $publication->id)->exists())->toBeFalse();
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/analytics'));
});

test('the analytics filter keeps upstream behaviour for unmapped channels and with the module off', function () {
    Http::fake([ZERNIO_EL_API.'/accounts*' => Http::response(zernioEligibilityAccounts())]);
    $unmapped = SocialAccount::factory()->tiktok()->create(['username' => 'other', 'status' => AccountStatus::TokenExpired]);
    $connected = SocialAccount::factory()->tiktok()->create(['username' => 'third']);

    expect(SocialAccount::query()->connectedForAnalytics()->pluck('id')->all())
        ->toContain($this->account->id, $connected->id)
        ->not->toContain($unmapped->id);

    config()->set('marmara-zernio.enabled', false);

    expect(SocialAccount::query()->connectedForAnalytics()->pluck('id')->all())
        ->toContain($connected->id)
        ->not->toContain($this->account->id);
});

test('the backfill job runs for a mapped channel whose native token expired', function () {
    Http::fake([
        ZERNIO_EL_API.'/accounts*' => Http::response(zernioEligibilityAccounts()),
        ZERNIO_EL_API.'/analytics*' => Http::response(['posts' => [], 'pagination' => ['page' => 1, 'pages' => 1]]),
    ]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $this->account->id,
        'checkpoint' => ['cursor' => null, 'revision' => 0],
    ]);

    app()->call([new BackfillAccountPublications($this->account->id, $state->id), 'handle']);

    expect($state->fresh()->isTerminal())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/analytics?'));
});

test('the connection checks judge a mapped channel by Zernio and bring it back to connected', function () {
    Http::fake([
        ZERNIO_EL_API.'/accounts*' => Http::response(zernioEligibilityAccounts()),
        '*' => Http::response(['error' => ['code' => 'access_token_invalid']], 401),
    ]);

    expect(app(ConnectionVerifier::class))->toBeInstanceOf(ZernioConnectionVerifier::class);

    app()->call([new VerifyWorkspaceConnections(Workspace::query()->findOrFail($this->account->workspace_id)), 'handle']);

    expect($this->account->fresh()->status)->toBe(AccountStatus::Connected);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'tiktokapis.com'));
});

test('a refused native token refresh does not expire a mapped channel', function () {
    $this->account->update(['status' => AccountStatus::Connected]);
    Http::fake([
        ZERNIO_EL_API.'/accounts*' => Http::response(zernioEligibilityAccounts()),
        '*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid or expired.'], 400),
    ]);

    app()->call([new RefreshSocialToken($this->account->fresh()), 'handle']);

    expect($this->account->fresh()->status)->toBe(AccountStatus::Connected);
});

test('a Zernio account reported disconnected expires the mapped channel', function () {
    $this->account->update(['status' => AccountStatus::Connected, 'last_verified_at' => null]);
    Http::fake([ZERNIO_EL_API.'/accounts*' => Http::response(zernioEligibilityAccounts(['isActive' => false]))]);

    app()->call([new VerifyWorkspaceConnections(Workspace::query()->findOrFail($this->account->workspace_id)), 'handle']);

    expect($this->account->fresh()->status)->toBe(AccountStatus::TokenExpired);
});

test('marmara:zernio:link creates a connected LinkedIn page channel for a Zernio-only page and the checks keep it', function () {
    $workspace = Workspace::query()->findOrFail($this->account->workspace_id);
    Http::fake([
        ZERNIO_EL_API.'/accounts*' => Http::response(['accounts' => [
            ['_id' => ZERNIO_EL_LINKEDIN, 'platform' => 'linkedin', 'username' => 'marmara-ai-kulubu', 'displayName' => 'Marmara Yapay Zeka Kulübü', 'profilePicture' => 'https://media.zernio.test/li.png', 'platformUserId' => 'urn:li:organization:104857600', 'isActive' => true],
        ]]),
        '*' => Http::response([], 500),
    ]);

    $this->artisan('marmara:zernio:link', ['zernioAccountId' => ZERNIO_EL_LINKEDIN, '--workspace' => $workspace->id])
        ->expectsOutputToContain('linkedin-page:')
        ->assertSuccessful();

    $page = SocialAccount::query()->where('platform', Platform::LinkedInPage)->sole();

    expect($page->status)->toBe(AccountStatus::Connected)
        ->and($page->username)->toBe('marmara-ai-kulubu')
        ->and($page->display_name)->toBe('Marmara Yapay Zeka Kulübü')
        ->and($page->avatar_url)->toBe('https://media.zernio.test/li.png')
        ->and($page->platform_user_id)->toBe('urn:li:organization:104857600')
        ->and(data_get($page->meta, 'organization_id'))->toBe('104857600')
        ->and(data_get($page->meta, 'managed_by'))->toBe('zernio')
        ->and($page->token_expires_at)->toBeNull();

    // Mapped, the page is checked by Zernio: no native LinkedIn call, no refresh.
    config()->set('marmara-zernio.accounts.linkedin-page', [(string) $page->id => ZERNIO_EL_LINKEDIN]);

    expect(app(ConnectionVerifier::class)->verify($page))->toBeTrue()
        ->and(app(ConnectionVerifier::class)->refreshToken($page))->toBeFalse()
        ->and(Platform::LinkedInPage->isIncludedInAnalytics())->toBeTrue();
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'linkedin.com'));

    // A second run updates the same channel.
    $this->artisan('marmara:zernio:link', ['zernioAccountId' => ZERNIO_EL_LINKEDIN, '--workspace' => $workspace->id])
        ->expectsOutputToContain('Already mapped')
        ->assertSuccessful();

    expect(SocialAccount::query()->where('platform', Platform::LinkedInPage)->count())->toBe(1);
});

test('a post whose Zernio analytics are pending is neither cached nor written as zeros', function () {
    $pending = ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0];
    Http::fake([
        ZERNIO_EL_API.'/accounts*' => Http::response(zernioEligibilityAccounts()),
        ZERNIO_EL_API.'/analytics*' => function (Request $request) use ($pending) {
            return filled($request['postId'] ?? null)
                ? Http::response(['analytics' => $pending, 'syncStatus' => 'pending', 'platformAnalytics' => [
                    ['platform' => 'tiktok', 'accountId' => ZERNIO_EL_TIKTOK, 'platformPostId' => '7400000000000000007', 'syncStatus' => 'pending', 'analytics' => $pending],
                ]])
                : Http::response(['posts' => [[
                    '_id' => 'ext-7',
                    'publishedAt' => '2026-11-14T08:00:00Z',
                    'mediaType' => 'video',
                    'analytics' => $pending,
                    'platforms' => [['platform' => 'tiktok', 'accountId' => ZERNIO_EL_TIKTOK, 'platformPostId' => '7400000000000000007', 'syncStatus' => 'pending', 'analytics' => $pending]],
                ]], 'pagination' => ['page' => 1, 'pages' => 1]]);
        },
    ]);
    $this->account->update(['status' => AccountStatus::Connected]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $this->account->id,
        'checkpoint' => ['cursor' => null, 'revision' => 0],
    ]);

    app()->call([new BackfillAccountPublications($this->account->id, $state->id), 'handle']);
    $publication = AnalyticsPublication::query()->where('remote_id', '7400000000000000007')->sole();

    app()->call([new CollectPublicationMetrics($publication->id, '2026-11-14', true), 'handle']);

    expect(AnalyticsPublicationDailySnapshot::query()->where('publication_id', $publication->id)->exists())->toBeFalse();
    // Not served from a cache: the metrics read asked Zernio for the post itself.
    Http::assertSent(fn (Request $request): bool => ($request['postId'] ?? null) === '7400000000000000007');
    // Read again later today, like a network that has not produced metrics yet.
    Queue::assertPushed(CollectPublicationMetrics::class, fn (CollectPublicationMetrics $job): bool => $job->publicationId === $publication->id && $job->retryNumber === 1);
});

test('a post Zernio can never sync is refused as unsupported, not measured as zeros', function () {
    Http::fake([
        ZERNIO_EL_API.'/analytics*' => Http::response(['analytics' => ['views' => 0], 'syncStatus' => 'unavailable', 'platformAnalytics' => [
            ['platform' => 'tiktok', 'accountId' => ZERNIO_EL_TIKTOK, 'syncStatus' => 'unavailable', 'analytics' => null],
        ]]),
    ]);
    $publication = zernioEligibilityPublication($this->account, '7400000000000000008')->setRelation('socialAccount', $this->account);

    expect(fn () => app(TikTokPublicationMetricsCollector::class)->collect($publication, CarbonImmutable::now('UTC')->startOfDay()))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('unsupported'));
});
