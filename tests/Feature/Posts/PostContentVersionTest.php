<?php

declare(strict_types=1);

use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\SocialAccount;
use App\Models\WorkspaceLabel;
use App\Services\WebhookService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    ['user' => $this->owner, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
    $this->channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $this->post = Post::factory()->forAccount($this->channel, ContentType::LinkedInPost)->pendingApproval()->create([
        'user_id' => $this->owner->id,
        'content' => 'Launch day',
        'scheduled_at' => '2026-12-01 09:00:00',
        'meta' => ['is_ai_generated' => true],
        'media' => [
            ['id' => 'm_01', 'path' => 'medias/hero.jpg', 'url' => 'https://cdn.example.com/medias/hero.jpg', 'meta' => ['width' => 1080, 'alt_text' => 'A laptop']],
            ['id' => 'm_02', 'path' => 'medias/clip.mp4', 'url' => 'https://cdn.example.com/medias/clip.mp4'],
        ],
    ]);
});

test('the content version is a short hash that reads the same from the saved post and the database', function () {
    expect($this->post->contentVersion())
        ->toMatch('/^[0-9a-f]{16}$/')
        ->toBe($this->post->fresh()->contentVersion());
});

test('the api post resource and the webhook payload send the same content version', function () {
    $version = $this->post->fresh()->contentVersion();

    $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.posts.show', $this->post))
        ->assertOk()
        ->assertJsonPath('content_version', $version);

    expect(app(WebhookService::class)->postPayload($this->post->fresh())['content_version'])->toBe($version);
});

test('the content version changes when what gets published changes', function (array $change) {
    $before = $this->post->contentVersion();

    $this->post->update($change);

    expect($this->post->fresh()->contentVersion())->not->toBe($before);
})->with([
    'content' => [['content' => 'Launch week']],
    'media order' => [['media' => [
        ['id' => 'm_02', 'path' => 'medias/clip.mp4', 'url' => 'https://cdn.example.com/medias/clip.mp4'],
        ['id' => 'm_01', 'path' => 'medias/hero.jpg', 'url' => 'https://cdn.example.com/medias/hero.jpg', 'meta' => ['width' => 1080, 'alt_text' => 'A laptop']],
    ]]],
    'media removed' => [['media' => [
        ['id' => 'm_01', 'path' => 'medias/hero.jpg', 'url' => 'https://cdn.example.com/medias/hero.jpg', 'meta' => ['width' => 1080, 'alt_text' => 'A laptop']],
    ]]],
    'alt text' => [['media' => [
        ['id' => 'm_01', 'path' => 'medias/hero.jpg', 'url' => 'https://cdn.example.com/medias/hero.jpg', 'meta' => ['width' => 1080, 'alt_text' => 'A phone']],
        ['id' => 'm_02', 'path' => 'medias/clip.mp4', 'url' => 'https://cdn.example.com/medias/clip.mp4'],
    ]]],
    'time' => [['scheduled_at' => '2026-12-01 09:30:00']],
    'queue' => [['schedule_mode' => ScheduleMode::Queue]],
    'content type' => [['content_type' => ContentType::LinkedInComment]],
    'channel options' => [['meta' => ['is_ai_generated' => true, 'first_comment' => 'Link in the comments']]],
]);

test('the content version ignores what does not change the published post', function (array $change) {
    $before = $this->post->contentVersion();

    $this->post->update($change);

    expect($this->post->fresh()->contentVersion())->toBe($before);
})->with([
    'status' => [['status' => PostStatus::Scheduled]],
    'approval' => [['approved_at' => '2026-11-11 10:00:00', 'approved_by' => null]],
    'error message' => [['error_message' => 'Token expired']],
    'surrounding whitespace' => [['content' => "  Launch day \n"]],
    'stored media details' => [['media' => [
        ['id' => 'm_01', 'path' => 'medias/hero.jpg', 'url' => 'https://cdn.example.com/other/hero.jpg', 'meta' => ['width' => 1200, 'alt_text' => 'A laptop']],
        ['id' => 'm_02', 'path' => 'medias/clip.mp4', 'url' => 'https://cdn.example.com/medias/clip.mp4', 'size' => 1024],
    ]]],
]);

test('notes and labels do not change the content version', function () {
    $before = $this->post->contentVersion();

    PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->owner->id]);
    $this->post->labels()->attach(WorkspaceLabel::factory()->recycle($this->workspace)->create());

    expect($this->post->fresh()->contentVersion())->toBe($before);
});

test('a queued post keeps its content version when the queue moves its slot', function () {
    $this->post->update(['schedule_mode' => ScheduleMode::Queue]);
    $before = $this->post->contentVersion();

    $this->post->update(['scheduled_at' => '2026-12-02 09:00:00']);

    expect($this->post->fresh()->contentVersion())->toBe($before);
});

test('the key order of the stored channel options does not change the content version', function () {
    $this->post->update(['meta' => ['is_ai_generated' => true, 'first_comment' => 'Hi']]);
    $before = $this->post->contentVersion();

    DB::table('posts')->where('id', $this->post->id)->update(['meta' => json_encode(['first_comment' => 'Hi', 'is_ai_generated' => true])]);

    expect($this->post->fresh()->contentVersion())->toBe($before);
});
