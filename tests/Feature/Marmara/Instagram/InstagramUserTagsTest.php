<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Marmara\Instagram\InstagramUserTags;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\ContentTypeCompatibleWithMedia;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\InstagramPublisher;
use App\Support\PostMediaRules;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'ig_marmara',
        'username' => 'marmaraai',
        'token_expires_at' => now()->addDays(60),
    ]);
    $this->graph = Platform::Instagram->graphApiUrl();
});

/**
 * @param  list<array<string, mixed>>  $media
 */
function marmaraTaggedPost(SocialAccount $account, ContentType $contentType, array $media): Post
{
    $post = Post::factory()->forAccount($account)->create([
        'user_id' => Workspace::query()->whereKey($account->workspace_id)->value('user_id'),
        'content' => 'Marmara AI',
        'content_type' => $contentType,
        'media' => $media,
    ]);
    $post->setRelation('socialAccount', $account);

    return $post;
}

/**
 * @param  array<string, mixed>  $meta
 * @return array<string, mixed>
 */
function marmaraTaggedItem(string $kind, array $meta = []): array
{
    return [
        'id' => "tagged-{$kind}",
        'path' => $kind === 'video' ? 'media/2026-11/tagged.mp4' : 'media/2026-11/tagged.jpg',
        'url' => $kind === 'video' ? 'https://example.com/media/2026-11/tagged.mp4' : 'https://example.com/media/2026-11/tagged.jpg',
        'mime_type' => $kind === 'video' ? 'video/mp4' : 'image/jpeg',
        'original_filename' => $kind === 'video' ? 'tagged.mp4' : 'tagged.jpg',
        'meta' => $meta,
    ];
}

function marmaraFakeGraph(string $graph, string $published): void
{
    Http::fake([
        "{$graph}/ig_marmara/media" => Http::response(['id' => 'tag-container']),
        "{$graph}/tag-container*" => Http::response(['status_code' => 'FINISHED']),
        "{$graph}/ig_marmara/media_publish" => Http::response(['id' => $published]),
        "{$graph}/{$published}*" => Http::response(['permalink' => "https://www.instagram.com/p/{$published}/"]),
        '*' => Http::response((string) file_get_contents(__DIR__.'/../../../fixtures/1x1.png'), 200, ['Content-Type' => 'image/png']),
    ]);
}

/**
 * The `user_tags` of the container creation request.
 *
 * @return list<array<string, mixed>>|null
 */
function marmaraSentUserTags(string $graph): ?array
{
    $sent = Http::recorded(fn (Request $request): bool => $request->url() === "{$graph}/ig_marmara/media")
        ->map(fn (array $pair): mixed => data_get($pair[0]->data(), 'user_tags'))
        ->first();

    return is_string($sent) ? json_decode($sent, true) : null;
}

test('stories and reels take people tags; only a feed image needs a position', function (ContentType $contentType, bool $supports, bool $needsPosition) {
    expect($contentType->supportsUserTags())->toBe($supports)
        ->and($contentType->mediaRules()['supports_user_tags'])->toBe($supports)
        ->and(InstagramUserTags::needsPosition($contentType))->toBe($needsPosition);
})->with([
    'feed' => [ContentType::InstagramFeed, true, true],
    'story' => [ContentType::InstagramStory, true, false],
    'reel' => [ContentType::InstagramReel, true, false],
    'facebook story' => [ContentType::FacebookStory, false, false],
    'tiktok video' => [ContentType::TikTokVideo, false, false],
]);

test('an image story sends its tags by username, with or without a position', function () {
    Storage::fake();
    $optimizer = Mockery::mock(MediaOptimizer::class);
    $optimizer->shouldReceive('fitToCanvas')->once()->andReturnUsing(function (string $tempFile): string {
        $out = tempnam(sys_get_temp_dir(), 'ig_fit_');
        copy($tempFile, $out);

        return $out;
    });
    app()->instance(MediaOptimizer::class, $optimizer);
    marmaraFakeGraph($this->graph, 'story-image');

    (new InstagramPublisher)->publish(marmaraTaggedPost($this->account, ContentType::InstagramStory, [
        marmaraTaggedItem('image', ['user_tags' => [['username' => '@akbank'], ['username' => 'marmaraai', 'x' => 0.2, 'y' => 0.4]]]),
    ]));

    expect(marmaraSentUserTags($this->graph))->toBe([['username' => 'akbank'], ['username' => 'marmaraai']]);
});

test('a video story sends its tags by username', function () {
    marmaraFakeGraph($this->graph, 'story-video');

    (new InstagramPublisher)->publish(marmaraTaggedPost($this->account, ContentType::InstagramStory, [
        marmaraTaggedItem('video', ['duration' => 10, 'user_tags' => [['username' => 'akbank'], ['username' => 'akbank']]]),
    ]));

    Http::assertSent(fn (Request $request): bool => $request->url() === "{$this->graph}/ig_marmara/media"
        && data_get($request->data(), 'media_type') === 'STORIES'
        && data_get($request->data(), 'video_url') === 'https://example.com/media/2026-11/tagged.mp4');
    expect(marmaraSentUserTags($this->graph))->toBe([['username' => 'akbank']]);
});

test('a reel sends its tags by username, at most twenty', function () {
    marmaraFakeGraph($this->graph, 'reel-tags');
    $tags = array_map(fn (int $index): array => ['username' => "member{$index}"], range(1, 22));

    (new InstagramPublisher)->publish(marmaraTaggedPost($this->account, ContentType::InstagramReel, [
        marmaraTaggedItem('video', ['duration' => 10, 'user_tags' => $tags]),
    ]));

    $sent = marmaraSentUserTags($this->graph);

    Http::assertSent(fn (Request $request): bool => $request->url() === "{$this->graph}/ig_marmara/media"
        && data_get($request->data(), 'media_type') === 'REELS');
    expect($sent)->toHaveCount(20)
        ->and($sent[0])->toBe(['username' => 'member1']);
});

test('a reel without tags sends no user_tags', function () {
    marmaraFakeGraph($this->graph, 'reel-plain');

    (new InstagramPublisher)->publish(marmaraTaggedPost($this->account, ContentType::InstagramReel, [
        marmaraTaggedItem('video', ['duration' => 10]),
    ]));

    expect(marmaraSentUserTags($this->graph))->toBeNull();
});

test('a feed image still needs x and y on every tag', function () {
    $media = [marmaraTaggedItem('image', ['user_tags' => [['username' => 'akbank']]])];

    expect(ContentTypeCompatibleWithMedia::errorsFor([['key' => 'content_type', 'content_type' => ContentType::InstagramFeed->value]], $media))
        ->toBe(['content_type' => InstagramUserTags::positionRequiredMessage()])
        ->and(ContentTypeCompatibleWithMedia::errorsFor([['key' => 'content_type', 'content_type' => ContentType::InstagramFeed->value]], [
            marmaraTaggedItem('image', ['user_tags' => [['username' => 'akbank', 'x' => 0.5, 'y' => 0.5]]]),
        ]))->toBe([])
        // The feed publisher leaves a tag without a position out.
        ->and(MediaItem::fromArray($media[0])->userTags())->toBe([]);
});

test('a story or reel accepts tags without a position', function (ContentType $contentType, string $kind) {
    $media = [marmaraTaggedItem($kind, ['user_tags' => [['username' => 'akbank'], ['username' => 'marmara.ai']]])];

    expect(ContentTypeCompatibleWithMedia::errorsFor([['key' => 'content_type', 'content_type' => $contentType->value]], $media))->toBe([]);
})->with([
    'image story' => [ContentType::InstagramStory, 'image'],
    'video story' => [ContentType::InstagramStory, 'video'],
    'reel' => [ContentType::InstagramReel, 'video'],
]);

test('the media meta rules accept a username-only tag and still refuse half a position', function (array $tags, bool $passes) {
    $validator = Validator::make(
        ['media' => [[...marmaraTaggedItem('image'), 'meta' => ['user_tags' => $tags]]]],
        PostMediaRules::hostedRules(),
    );

    expect($validator->passes())->toBe($passes);
})->with([
    'username only' => [[['username' => 'akbank']], true],
    'username with @' => [[['username' => '@akbank']], true],
    'with a position' => [[['username' => 'akbank', 'x' => 0.5, 'y' => 0.5]], true],
    'x without y' => [[['username' => 'akbank', 'x' => 0.5]], false],
    'position outside the image' => [[['username' => 'akbank', 'x' => 1.5, 'y' => 0.5]], false],
    'invalid username' => [[['username' => 'not valid']], false],
    'more than twenty' => [array_fill(0, 21, ['username' => 'akbank']), false],
]);
