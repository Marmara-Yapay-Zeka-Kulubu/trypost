<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\Webhook\EventType;
use App\Jobs\DispatchWebhook;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\Webhook;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    ['user' => $this->owner, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
    $this->ada = workspaceMember($this->workspace, 'member', ['name' => 'Ada Lovelace']);
    $this->bob = workspaceMember($this->workspace, 'approval', ['name' => 'Bob Builder']);
    $this->post = Post::factory()->draft()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id]);

    Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [EventType::PostFeedbackRequested->value],
    ]);
});

/**
 * @return Collection<int, DispatchWebhook>
 */
function feedbackWebhooks(): Collection
{
    return Queue::pushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === EventType::PostFeedbackRequested->value);
}

test('asking members for feedback leaves a note by the requester and sends the webhook with who was asked', function () {
    $this->freezeTime();

    $this->actingAs($this->owner)
        ->postJson(route('app.posts.feedback-requests.store', $this->post), [
            'user_ids' => [$this->bob->id, $this->ada->id],
            'note' => '  Is the hook strong enough?  ',
        ])
        ->assertCreated()
        ->assertJsonPath('user.id', $this->owner->id);

    $note = PostNote::query()->where('post_id', $this->post->id)->sole();

    expect($note->user_id)->toBe($this->owner->id)
        ->and($note->body)->toBe(__('notes.feedback_requested_with_note', [
            'names' => 'Ada Lovelace, Bob Builder',
            'note' => 'Is the hook strong enough?',
        ], $this->owner->preferredLocale()))
        ->and($this->post->fresh()->status)->toBe(PostStatus::Draft)
        ->and(feedbackWebhooks())->toHaveCount(1);

    $payload = feedbackWebhooks()->sole()->payload;

    expect(data_get($payload, 'id'))->toBe($this->post->id)
        ->and(data_get($payload, 'status'))->toBe(PostStatus::Draft->value)
        ->and(data_get($payload, 'feedback.requested_by'))->toBe(['id' => $this->owner->id, 'name' => $this->owner->name])
        ->and(data_get($payload, 'feedback.note'))->toBe('Is the hook strong enough?')
        ->and(data_get($payload, 'feedback.requested_at'))->toBe(now()->toIso8601String())
        ->and(data_get($payload, 'feedback.recipients'))->toBe([
            ['id' => $this->ada->id, 'name' => 'Ada Lovelace'],
            ['id' => $this->bob->id, 'name' => 'Bob Builder'],
        ]);
});

test('a request without a note says only who was asked', function () {
    $this->actingAs($this->owner)
        ->postJson(route('app.posts.feedback-requests.store', $this->post), ['user_ids' => [$this->ada->id]])
        ->assertCreated();

    expect(PostNote::query()->where('post_id', $this->post->id)->sole()->body)
        ->toBe(__('notes.feedback_requested', ['names' => 'Ada Lovelace'], $this->owner->preferredLocale()))
        ->and(data_get(feedbackWebhooks()->sole()->payload, 'feedback.note'))->toBeNull();
});

test('the picker lists the other members of the workspace, never the member asking', function () {
    $this->owner->update(['name' => 'Zoe Owner']);

    $this->actingAs($this->ada)
        ->getJson(route('app.posts.feedback-requests.recipients', $this->post))
        ->assertOk()
        ->assertExactJson([
            ['id' => $this->bob->id, 'name' => 'Bob Builder', 'photo_url' => null],
            ['id' => $this->owner->id, 'name' => 'Zoe Owner', 'photo_url' => null],
        ]);
});

test('a request is refused when it asks nobody, the requester or someone outside the workspace', function (Closure $userIds, string $field) {
    $this->actingAs($this->owner)
        ->postJson(route('app.posts.feedback-requests.store', $this->post), ['user_ids' => $userIds($this)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(PostNote::query()->where('post_id', $this->post->id)->exists())->toBeFalse()
        ->and(feedbackWebhooks())->toBeEmpty();
})->with([
    'nobody' => [fn (): array => [], 'user_ids'],
    'the requester' => [fn (object $test): array => [$test->owner->id], 'user_ids.0'],
    'an outsider' => [fn (object $test): array => [workspaceOutsider($test->workspace)->id], 'user_ids.0'],
    'a member of another workspace' => [fn (): array => [parityContext()['user']->id], 'user_ids.0'],
    'too many' => [fn (object $test): array => array_fill(0, 21, $test->ada->id), 'user_ids'],
]);

test('a post of another workspace answers 404 on the web', function () {
    $other = parityContext();
    $foreign = Post::factory()->draft()->create(['workspace_id' => $other['workspace']->id, 'user_id' => $other['user']->id]);

    $this->actingAs($this->owner)
        ->postJson(route('app.posts.feedback-requests.store', $foreign), ['user_ids' => [$this->ada->id]])
        ->assertNotFound();
    $this->actingAs($this->owner)
        ->getJson(route('app.posts.feedback-requests.recipients', $foreign))
        ->assertNotFound();

    expect(PostNote::query()->where('post_id', $foreign->id)->exists())->toBeFalse()
        ->and(feedbackWebhooks())->toBeEmpty();
});

test('the api asks members for feedback the same way', function () {
    $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.posts.feedback-requests.store', $this->post), [
            'user_ids' => [$this->ada->id],
            'note' => 'Thoughts?',
        ])
        ->assertCreated()
        ->assertJsonPath('post_id', $this->post->id)
        ->assertJsonPath('author.id', $this->owner->id);

    expect(PostNote::query()->where('post_id', $this->post->id)->sole()->body)
        ->toBe(__('notes.feedback_requested_with_note', ['names' => 'Ada Lovelace', 'note' => 'Thoughts?'], $this->owner->preferredLocale()))
        ->and(data_get(feedbackWebhooks()->sole()->payload, 'feedback.recipients'))->toBe([['id' => $this->ada->id, 'name' => 'Ada Lovelace']]);
});

test('the api refuses a post of another workspace with 404 and someone outside the workspace with 422', function () {
    $other = parityContext();
    $foreign = Post::factory()->draft()->create(['workspace_id' => $other['workspace']->id, 'user_id' => $other['user']->id]);

    $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.posts.feedback-requests.store', $foreign), ['user_ids' => [$other['user']->id]])
        ->assertNotFound();
    auth()->forgetGuards();

    $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.posts.feedback-requests.store', $this->post), ['user_ids' => [$other['user']->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user_ids.0']);

    expect(PostNote::query()->exists())->toBeFalse()
        ->and(feedbackWebhooks())->toBeEmpty();
});

test('the feedback webhook goes only to webhooks subscribed to it', function () {
    Webhook::query()->update(['events' => [EventType::PostCreated->value]]);

    $this->actingAs($this->owner)
        ->postJson(route('app.posts.feedback-requests.store', $this->post), ['user_ids' => [$this->ada->id]])
        ->assertCreated();

    expect(feedbackWebhooks())->toBeEmpty();
});
