<?php

declare(strict_types=1);

use App\Actions\Post\Approval\ApprovePost;
use App\Actions\Post\Approval\RejectPost;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\Webhook\EventType;
use App\Jobs\DispatchWebhook;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([DispatchWebhook::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Europe/Istanbul'));

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->requester = workspaceMember($this->workspace, 'approval');

    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [EventType::PostApprovalRequested->value, EventType::PostApproved->value, EventType::PostRejected->value],
    ]);
});

function requestApprovalForWebhookPost(object $test): Post
{
    $test->actingAs($test->requester)
        ->post(route('app.posts.store'), [
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'content' => 'Needs a green light',
            'media' => [],
            'destinations' => [[
                'social_account_id' => $test->channel->id,
                'content_type' => ContentType::LinkedInPost->value,
                'meta' => [],
            ]],
        ])
        ->assertSessionHasNoErrors();

    return Post::query()->findOrFail(session('created_post_ids')[0]);
}

test('a post sent for approval queues a post.approval_requested webhook with who asked', function () {
    $post = requestApprovalForWebhookPost($this);

    expect($post->status)->toBe(PostStatus::PendingApproval);

    Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === EventType::PostApprovalRequested->value
        && data_get($job->payload, 'id') === $post->id
        && data_get($job->payload, 'status') === PostStatus::PendingApproval->value
        && data_get($job->payload, 'approval.requested_by.id') === $this->requester->id
        && data_get($job->payload, 'approval.decided_by') === null);
});

test('approving a pending post queues a post.approved webhook with who decided', function () {
    $post = requestApprovalForWebhookPost($this);

    ApprovePost::execute($post, $this->owner);

    Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === EventType::PostApproved->value
        && data_get($job->payload, 'id') === $post->id
        && data_get($job->payload, 'approval.requested_by.id') === $this->requester->id
        && data_get($job->payload, 'approval.decided_by.id') === $this->owner->id);
    Queue::assertNotPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === EventType::PostRejected->value);
});

test('rejecting a pending post queues a post.rejected webhook', function () {
    $post = requestApprovalForWebhookPost($this);

    RejectPost::execute($post, $this->owner);

    Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === EventType::PostRejected->value
        && data_get($job->payload, 'id') === $post->id
        && data_get($job->payload, 'status') === PostStatus::Draft->value
        && data_get($job->payload, 'approval.decided_by.id') === $this->owner->id);
});

test('approval webhooks are not sent to webhooks that did not subscribe to them', function () {
    Webhook::query()->update(['events' => [EventType::PostCreated->value]]);

    requestApprovalForWebhookPost($this);

    Queue::assertNotPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === EventType::PostApprovalRequested->value);
});

test('the approval request webhook carries the version that approves the post until it is edited', function () {
    $post = requestApprovalForWebhookPost($this);
    $sent = data_get(Queue::pushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === EventType::PostApprovalRequested->value)->sole()->payload, 'content_version');

    expect($sent)->toBe($post->contentVersion());

    $this->actingAs($this->requester)
        ->put(route('app.posts.update', $post), ['status' => 'scheduled', 'content_type' => ContentType::LinkedInPost->value, 'content' => 'Second try'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->owner)
        ->put(route('app.posts.approve', $post), ['expected_version' => $sent])
        ->assertSessionHasErrors(['post' => __('posts.approvals.errors.changed')]);

    expect($post->fresh()->status)->toBe(PostStatus::PendingApproval);
    Queue::assertNotPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === EventType::PostApproved->value);

    $this->actingAs($this->owner)
        ->put(route('app.posts.approve', $post), ['expected_version' => $post->fresh()->contentVersion()])
        ->assertSessionHasNoErrors();

    expect($post->fresh()->status)->toBe(PostStatus::Scheduled);
    Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === EventType::PostApproved->value
        && data_get($job->payload, 'content_version') === $post->fresh()->contentVersion());
});
