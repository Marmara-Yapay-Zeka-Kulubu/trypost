<?php

declare(strict_types=1);

use App\Enums\Post\PublishStatus as PlatformStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\TikTok\PrivacyLevel;
use App\Exceptions\Social\ErrorCategory;
use App\Jobs\PublishToSocialPlatform;
use App\Jobs\ResolveTikTokVideoId;
use App\Marmara\Zernio\ZernioTikTokPublisher;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\TikTokPublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

const ZERNIO_API = 'https://zernio.com/api/v1';
const ZERNIO_ACCOUNT = '66b2e19d8c3f5a7e9d0b1c2d';
const ZERNIO_POST = '6a0f6d2e520992756d96bb6c';
const ZERNIO_VIDEO_ID = '7694308097568836885';

beforeEach(function () {
    Event::fake();
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);

    config()->set('marmara-zernio', [
        'enabled' => true,
        'api_key' => 'sk_test_key',
        'base_url' => ZERNIO_API,
        'timeout' => 30,
        'tiktok' => [
            'accounts' => ['@marmaraai' => ZERNIO_ACCOUNT],
            'media_mode' => 'url',
            'status_poll_seconds' => 60,
            'status_window_minutes' => 60,
        ],
    ]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'tt-marmara',
        'username' => 'marmaraai',
        'token_expires_at' => now()->addDay(),
    ]);
});

/**
 * @param  array<string, mixed>  $meta
 * @param  list<array<string, mixed>>|null  $media
 */
function zernioTikTokPost(SocialAccount $account, User $user, array $meta = [], ?array $media = null, ContentType $contentType = ContentType::TikTokVideo): Post
{
    $post = Post::factory()->forAccount($account)->scheduled()->create(['user_id' => $user->id]);

    $post->forceFill([
        'content' => 'Marmara AI kulübü tanıtım videosu',
        'content_type' => $contentType,
        'scheduled_before_media_checks' => true,
        'meta' => ['privacy_level' => PrivacyLevel::PublicToEveryone->value, ...$meta],
        'media' => $media ?? [[
            'id' => 'video-1',
            'type' => 'video',
            'path' => 'medias/club.mp4',
            'url' => 'https://example.com/medias/club.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'club.mp4',
        ]],
    ])->save();

    return $post->fresh();
}

/**
 * @param  array<string, mixed>  $target
 * @return array<string, mixed>
 */
function zernioPostBody(string $status, array $target = []): array
{
    return ['post' => [
        '_id' => ZERNIO_POST,
        'status' => $status,
        'platforms' => [[
            'platform' => 'tiktok',
            'accountId' => ZERNIO_ACCOUNT,
            'status' => $status,
            ...$target,
        ]],
    ]];
}

test('the container hands out the native TikTok publisher while Zernio is disabled', function () {
    config()->set('marmara-zernio.enabled', false);

    expect(app(TikTokPublisher::class))->toBeInstanceOf(TikTokPublisher::class)
        ->not->toBeInstanceOf(ZernioTikTokPublisher::class);

    config()->set('marmara-zernio.enabled', true);

    expect(app(TikTokPublisher::class))->toBeInstanceOf(ZernioTikTokPublisher::class);
});

test('a mapped TikTok post is published through Zernio and keeps the TikTok video url', function () {
    $post = zernioTikTokPost($this->account, $this->user, ['allow_comments' => true, 'is_aigc' => true, 'brand_organic_toggle' => true]);

    Http::fake([
        ZERNIO_API.'/posts' => Http::response(zernioPostBody('published', [
            'platformPostId' => ZERNIO_VIDEO_ID,
            'platformPostUrl' => 'https://www.tiktok.com/@marmaraai/video/'.ZERNIO_VIDEO_ID,
        ]), 201),
    ]);

    (new PublishToSocialPlatform($post))->handle();

    $post->refresh();
    expect($post->publish_status)->toBe(PlatformStatus::Published)
        ->and($post->platform_post_id)->toBe(ZERNIO_VIDEO_ID)
        ->and($post->platform_url)->toBe('https://www.tiktok.com/@marmaraai/video/'.ZERNIO_VIDEO_ID)
        ->and($post->error_context)->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === ZERNIO_API.'/posts'
        && $request->hasHeader('Authorization', 'Bearer sk_test_key')
        && filled($request->header('Idempotency-Key')[0] ?? null)
        && $request['publishNow'] === true
        && ! array_key_exists('scheduledFor', $request->data())
        && $request['content'] === 'Marmara AI kulübü tanıtım videosu'
        && $request['platforms'] === [['platform' => 'tiktok', 'accountId' => ZERNIO_ACCOUNT]]
        && $request['mediaItems'] === [['type' => 'video', 'url' => 'https://example.com/medias/club.mp4']]
        && $request['tiktokSettings']['privacy_level'] === 'PUBLIC_TO_EVERYONE'
        && $request['tiktokSettings']['allow_comment'] === true
        && $request['tiktokSettings']['allow_duet'] === false
        && $request['tiktokSettings']['allow_stitch'] === false
        && $request['tiktokSettings']['is_aigc'] === true
        && $request['tiktokSettings']['commercialContentType'] === 'brand_organic'
        && $request['tiktokSettings']['content_preview_confirmed'] === true
        && $request['tiktokSettings']['express_consent_given'] === true);
    Http::assertSentCount(1);
    Queue::assertNotPushed(ResolveTikTokVideoId::class);
});

test('a photo carousel goes to Zernio as a photo post with the caption in the description', function () {
    $post = zernioTikTokPost($this->account, $this->user, ['auto_add_music' => true], [
        ['id' => 'p1', 'type' => 'image', 'path' => 'medias/1.jpg', 'url' => 'https://example.com/medias/1.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => '1.jpg'],
        ['id' => 'p2', 'type' => 'image', 'path' => 'medias/2.jpg', 'url' => 'https://example.com/medias/2.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => '2.jpg'],
    ], ContentType::TikTokPhoto);

    Http::fake([
        ZERNIO_API.'/posts' => Http::response(zernioPostBody('published', ['platformPostId' => ZERNIO_VIDEO_ID]), 201),
    ]);

    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Published);
    Http::assertSent(fn (Request $request): bool => $request->url() === ZERNIO_API.'/posts'
        && ! array_key_exists('content', $request->data())
        && count($request['mediaItems']) === 2
        && $request['tiktokSettings']['media_type'] === 'photo'
        && $request['tiktokSettings']['description'] === 'Marmara AI kulübü tanıtım videosu'
        && $request['tiktokSettings']['auto_add_music'] === true
        && ! array_key_exists('allow_duet', $request['tiktokSettings']));
});

test('media is uploaded to Zernio storage first in upload mode', function () {
    config()->set('marmara-zernio.tiktok.media_mode', 'upload');
    $post = zernioTikTokPost($this->account, $this->user);

    Http::fake([
        'https://example.com/medias/club.mp4' => Http::response('video-bytes'),
        ZERNIO_API.'/media/presign' => Http::response([
            'uploadUrl' => 'https://storage.zernio.test/temp/club.mp4?X-Amz-Signature=abc',
            'publicUrl' => 'https://media.zernio.com/temp/club.mp4',
            'key' => 'temp/club.mp4',
            'expiresIn' => 3600,
        ]),
        'https://storage.zernio.test/*' => Http::response('', 200),
        ZERNIO_API.'/posts' => Http::response(zernioPostBody('published', ['platformPostId' => ZERNIO_VIDEO_ID]), 201),
    ]);

    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Published);
    Http::assertSent(fn (Request $request): bool => $request->url() === ZERNIO_API.'/media/presign'
        && $request['filename'] === 'club.mp4'
        && $request['contentType'] === 'video/mp4');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_starts_with($request->url(), 'https://storage.zernio.test/temp/club.mp4')
        && ! $request->hasHeader('Authorization')
        && $request->body() === 'video-bytes');
    Http::assertSent(fn (Request $request): bool => $request->url() === ZERNIO_API.'/posts'
        && $request['mediaItems'] === [['type' => 'video', 'url' => 'https://media.zernio.com/temp/club.mp4']]);
});

test('a post TikTok has not given a video id yet keeps the Zernio id and is resolved later through Zernio', function () {
    $post = zernioTikTokPost($this->account, $this->user);

    Http::fake([
        ZERNIO_API.'/posts' => Http::response(zernioPostBody('published'), 201),
        ZERNIO_API.'/posts/'.ZERNIO_POST => Http::response(zernioPostBody('published', [
            'platformPostId' => ZERNIO_VIDEO_ID,
        ])),
    ]);

    (new PublishToSocialPlatform($post))->handle();

    $post->refresh();
    expect($post->publish_status)->toBe(PlatformStatus::Published)
        ->and($post->platform_post_id)->toBe('zernio:'.ZERNIO_POST)
        ->and($post->platform_url)->toBe('https://www.tiktok.com/@marmaraai')
        ->and($post->awaitsTikTokVideoId())->toBeTrue();
    Queue::assertPushed(ResolveTikTokVideoId::class);

    expect(app(TikTokPublisher::class)->publicVideoId($post))->toBe(ZERNIO_VIDEO_ID);
});

test('a TikTok publish Zernio reports as failed fails the TryPost post and drops the checkpoint', function () {
    $post = zernioTikTokPost($this->account, $this->user);

    Http::fake([
        ZERNIO_API.'/posts' => Http::response([
            ...zernioPostBody('failed', ['errorMessage' => 'Video is shorter than 3 seconds', 'errorCategory' => 'media', 'errorSource' => 'user']),
            'platformResults' => [['platform' => 'tiktok', 'status' => 'failed', 'error' => 'Video is shorter than 3 seconds']],
        ], 207),
    ]);

    (new PublishToSocialPlatform($post))->handle();

    $post->refresh();
    expect($post->publish_status)->toBe(PlatformStatus::Failed)
        ->and($post->error_message)->toContain('Video is shorter than 3 seconds')
        ->and(data_get($post->error_context, 'category'))->toBe(ErrorCategory::MediaFormat->value)
        ->and(data_get($post->error_context, ZernioTikTokPublisher::CHECKPOINT))->toBeNull()
        ->and($post->platform_post_id)->toBeNull();
});

test('a refused API key fails the post as a permission error', function () {
    $post = zernioTikTokPost($this->account, $this->user);

    Http::fake([
        ZERNIO_API.'/posts' => Http::response(['error' => 'Invalid API key', 'type' => 'authentication_error', 'code' => 'invalid_credentials'], 401),
    ]);

    (new PublishToSocialPlatform($post))->handle();

    $post->refresh();
    expect($post->publish_status)->toBe(PlatformStatus::Failed)
        ->and($post->error_message)->toContain('Invalid API key')
        ->and(data_get($post->error_context, 'category'))->toBe(ErrorCategory::Permission->value)
        ->and(data_get($post->error_context, 'platform_error_code'))->toBe('invalid_credentials');
});

test('a post TikTok is still processing is checked again later without being created twice', function () {
    $post = zernioTikTokPost($this->account, $this->user);

    Http::fake([
        ZERNIO_API.'/posts' => Http::response(zernioPostBody('processing'), 201),
        ZERNIO_API.'/posts/'.ZERNIO_POST => Http::response(zernioPostBody('published', [
            'platformPostId' => ZERNIO_VIDEO_ID,
        ])),
    ]);

    (new PublishToSocialPlatform($post))->handle();

    $post->refresh();
    expect($post->publish_status)->toBe(PlatformStatus::Retrying)
        ->and(data_get($post->error_context, ZernioTikTokPublisher::CHECKPOINT.'.post_id'))->toBe(ZERNIO_POST)
        ->and(data_get($post->error_context, 'retry_delay_seconds'))->toBe(60);
    Queue::assertPushed(PublishToSocialPlatform::class);

    (new PublishToSocialPlatform($post))->handle();

    $post->refresh();
    expect($post->publish_status)->toBe(PlatformStatus::Published)
        ->and($post->platform_post_id)->toBe(ZERNIO_VIDEO_ID);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === ZERNIO_API.'/posts/'.ZERNIO_POST);
});

test('a Zernio outage is retried with the same idempotency key', function () {
    $post = zernioTikTokPost($this->account, $this->user);

    Http::fake([
        ZERNIO_API.'/posts' => Http::sequence()
            ->push(['error' => 'Temporarily unavailable', 'code' => 'temporarily_unavailable'], 503)
            ->push(zernioPostBody('published', ['platformPostId' => ZERNIO_VIDEO_ID]), 201),
    ]);

    (new PublishToSocialPlatform($post))->handle();

    $post->refresh();
    $key = data_get($post->error_context, ZernioTikTokPublisher::CHECKPOINT.'.idempotency_key');
    expect($post->publish_status)->toBe(PlatformStatus::Retrying)
        ->and($key)->toBeString();

    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Published);
    $keys = Http::recorded()->map(fn (array $pair): ?string => $pair[0]->header('Idempotency-Key')[0] ?? null)->all();
    expect($keys)->toBe([$key, $key]);
});

test('a TikTok account that is not mapped keeps publishing natively while Zernio is enabled', function () {
    $other = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'tt-other',
        'username' => 'someoneelse',
        'token_expires_at' => now()->addDay(),
    ]);
    $post = zernioTikTokPost($other, $this->user);
    $api = config('trypost.platforms.tiktok.api');

    Http::fake([
        $api.'/post/publish/video/init/' => Http::response(['data' => ['publish_id' => 'pub_native']]),
        $api.'/post/publish/status/fetch/' => Http::response(['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => [ZERNIO_VIDEO_ID]]]),
    ]);

    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Published);
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), ZERNIO_API));
});

test('with Zernio disabled the mapped account publishes through the native TikTok API', function () {
    config()->set('marmara-zernio.enabled', false);
    $post = zernioTikTokPost($this->account, $this->user);
    $api = config('trypost.platforms.tiktok.api');

    Http::fake([
        $api.'/post/publish/video/init/' => Http::response(['data' => ['publish_id' => 'pub_native']]),
        $api.'/post/publish/status/fetch/' => Http::response(['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => [ZERNIO_VIDEO_ID]]]),
    ]);

    (new PublishToSocialPlatform($post))->handle();

    $post->refresh();
    expect($post->publish_status)->toBe(PlatformStatus::Published)
        ->and($post->platform_post_id)->toBe(ZERNIO_VIDEO_ID);
    Http::assertSent(fn (Request $request): bool => $request->url() === $api.'/post/publish/video/init/');
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), ZERNIO_API));
});
