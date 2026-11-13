<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Enums\TikTok\PrivacyLevel;
use App\Jobs\ResolveTikTokVideoId;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Social\TikTokCreatorInfo;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->zernioApi = 'https://zernio.com/api/v1';
    $this->zernioAccountId = '66b2e19d8c3f5a7e9d0b1c2d';
    $this->zernioPostId = '6a0f6d2e520992756d96bb6c';

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
    ]);

    $this->account = SocialAccount::factory()->tiktok()->create([
        'platform_user_id' => 'tt-marmara',
        'username' => 'marmaraai',
        'display_name' => 'Marmara AI',
        'token_expires_at' => now()->addDay(),
    ]);
});

function zernioPublishedPost(SocialAccount $account, string $zernioPostId): Post
{
    return Post::factory()->forAccount($account, ContentType::TikTokVideo)->published()->create([
        'platform_post_id' => 'zernio:'.$zernioPostId,
        'platform_url' => 'https://www.tiktok.com/@marmaraai',
        'meta' => ['privacy_level' => PrivacyLevel::PublicToEveryone->value],
    ]);
}

test('a Zernio channel offers the privacy levels and switches Zernio reports, not the native app ones', function () {
    Http::fake([
        $this->zernioApi.'/accounts/'.$this->zernioAccountId.'/tiktok/creator-info*' => Http::response([
            'creator' => ['nickname' => 'Marmara AI Kulübü', 'avatarUrl' => 'https://cdn.zernio.test/a.jpg', 'canPostMore' => true],
            'privacyLevels' => [
                ['value' => 'PUBLIC_TO_EVERYONE', 'label' => 'Everyone'],
                ['value' => 'MUTUAL_FOLLOW_FRIENDS', 'label' => 'Friends'],
            ],
            'postingLimits' => [
                'maxVideoDurationSec' => 600,
                'interactionSettings' => [
                    'allow_comment' => ['enabled' => true, 'required' => true, 'default' => true],
                    'allow_duet' => ['enabled' => true, 'required' => false, 'default' => false],
                    'allow_stitch' => ['enabled' => false, 'required' => false, 'default' => false],
                ],
            ],
        ]),
    ]);

    $info = app(TikTokCreatorInfo::class)->interactive()->fetchOrFail($this->account);

    expect($info)->toBe([
        'creator_nickname' => 'Marmara AI Kulübü',
        'creator_username' => 'marmaraai',
        'creator_avatar_url' => 'https://cdn.zernio.test/a.jpg',
        'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS'],
        'comment_disabled' => false,
        'duet_disabled' => false,
        'stitch_disabled' => true,
        'max_video_post_duration_sec' => 600,
    ]);

    app(TikTokCreatorInfo::class)->fetch($this->account);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'mediaType=video')
        && $request->hasHeader('Authorization', 'Bearer sk_test_key'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'creator_info/query'));
});

test('a Zernio channel still offers Everyone when Zernio cannot be asked', function () {
    Http::fake([
        $this->zernioApi.'/accounts/*' => Http::response(['error' => 'Temporarily unavailable'], 503),
    ]);

    $creatorInfo = app(TikTokCreatorInfo::class);

    expect($creatorInfo->fetch($this->account)['privacy_level_options'])->toBe(['PUBLIC_TO_EVERYONE'])
        ->and($creatorInfo->interactive()->fetchOrFail($this->account)['privacy_level_options'])->toBe(['PUBLIC_TO_EVERYONE'])
        ->and($creatorInfo->fetch($this->account)['creator_username'])->toBe('marmaraai');

    Http::assertSentCount(3);
});

test('a channel not mapped to Zernio keeps the native creator info', function () {
    $other = SocialAccount::factory()->tiktok()->create([
        'username' => 'someoneelse',
        'token_expires_at' => now()->addDay(),
    ]);

    Http::fake([
        config('trypost.platforms.tiktok.api').'/post/publish/creator_info/query/' => Http::response([
            'data' => ['privacy_level_options' => ['SELF_ONLY'], 'creator_username' => 'someoneelse'],
            'error' => ['code' => 'ok'],
        ]),
    ]);

    expect(app(TikTokCreatorInfo::class)->fetch($other)['privacy_level_options'])->toBe(['SELF_ONLY']);
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), $this->zernioApi));
});

test('a Zernio post gets its TikTok video id from Zernio although its native channel token expired', function () {
    $this->account->forceFill(['status' => AccountStatus::TokenExpired])->save();
    $post = zernioPublishedPost($this->account, $this->zernioPostId);

    Http::fake([
        $this->zernioApi.'/posts/'.$this->zernioPostId => Http::response(['post' => [
            '_id' => $this->zernioPostId,
            'status' => 'published',
            'platforms' => [[
                'platform' => 'tiktok',
                'accountId' => $this->zernioAccountId,
                'status' => 'published',
                'platformPostId' => '7694308097568836885',
            ]],
        ]]),
    ]);

    app()->call([new ResolveTikTokVideoId($post), 'handle']);

    $post->refresh();
    expect($post->platform_post_id)->toBe('7694308097568836885')
        ->and($post->platform_url)->toBe('https://www.tiktok.com/@marmaraai/video/7694308097568836885');
});

test('the Zernio sweep sends Zernio posts of channels that are not connected natively to the resolver', function () {
    $this->account->forceFill(['status' => AccountStatus::TokenExpired])->save();
    $expired = zernioPublishedPost($this->account, $this->zernioPostId);

    $connected = SocialAccount::factory()->tiktok()->create(['username' => 'marmaraai', 'platform_user_id' => 'tt-connected']);
    $covered = zernioPublishedPost($connected, 'bbbbbbbbbbbbbbbbbbbbbbbb');

    $this->artisan('marmara:zernio:resolve-video-ids')->assertSuccessful();

    Queue::assertPushed(ResolveTikTokVideoId::class, fn (ResolveTikTokVideoId $job): bool => $job->post->is($expired));
    Queue::assertNotPushed(ResolveTikTokVideoId::class, fn (ResolveTikTokVideoId $job): bool => $job->post->is($covered));
});

test('without Zernio a native channel that is not connected is still skipped by the resolver', function () {
    config()->set('marmara-zernio.enabled', false);
    $this->account->forceFill(['status' => AccountStatus::TokenExpired])->save();
    $post = zernioPublishedPost($this->account, $this->zernioPostId);

    app()->call([new ResolveTikTokVideoId($post), 'handle']);

    expect($post->fresh()->platform_post_id)->toBe('zernio:'.$this->zernioPostId);
    Http::assertNothingSent();
});
