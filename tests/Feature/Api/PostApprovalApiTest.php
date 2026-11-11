<?php

declare(strict_types=1);

use App\Actions\Post\Approval\ApprovePost;
use App\Actions\Post\CreatePosts;
use App\Enums\Post\ApprovalDecision;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\Webhook\EventType;
use App\Jobs\DispatchWebhook;
use App\Models\Post;
use App\Models\PostApprovalAction;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Support\PostApproval;
use App\Support\PostingSchedule;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    Queue::fake();
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->requester = workspaceMember($this->workspace, 'approval');
    $this->channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
        'timezone' => 'UTC',
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00')->withTime(4, '09:00'),
    ]);
    $this->ownerHeaders = ['Authorization' => 'Bearer '.passportToken($this->owner, $this->workspace)];
    $this->requesterHeaders = ['Authorization' => 'Bearer '.passportToken($this->requester, $this->workspace)];
});

function approvalApiRequest(object $test): Post
{
    return CreatePosts::execute($test->workspace, $test->requester, [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'API approval',
        'media' => [],
        'destinations' => [[
            'social_account_id' => $test->channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
    ])->sole();
}

test('an approver approves and rejects through the api', function () {
    $approved = approvalApiRequest($this);
    $rejected = approvalApiRequest($this);

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route('api.posts.approve', $approved))
        ->assertOk()
        ->assertJsonPath('status', PostStatus::Scheduled->value)
        ->assertJsonPath('schedule_mode', 'queue')
        ->assertJsonMissingPath('replayed');

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route('api.posts.reject', $rejected))
        ->assertOk()
        ->assertJsonPath('status', PostStatus::Draft->value)
        ->assertJsonMissingPath('replayed');

    expect(PostApprovalAction::query()->count())->toBe(0);
});

test('a member who needs approval cannot approve through the api', function () {
    $request = approvalApiRequest($this);

    $this->withHeaders($this->requesterHeaders)
        ->postJson(route('api.posts.approve', $request))
        ->assertForbidden();

    expect($request->fresh()->status)->toBe(PostStatus::PendingApproval);
});

test('approving a post that is not pending is unprocessable', function () {
    $request = approvalApiRequest($this);
    $this->withHeaders($this->ownerHeaders)->postJson(route('api.posts.reject', $request))->assertOk();

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route('api.posts.approve', $request))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('post')
        ->assertJsonPath('error', 'not_pending');
});

test('approving while another approval holds the post is a conflict', function () {
    $request = approvalApiRequest($this);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
    Cache::partialMock()->shouldReceive('lock')->with("post-approval:{$request->id}", PostApproval::LOCK_SECONDS)->andReturn($lock);

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route('api.posts.approve', $request))
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertJsonPath('message', __('posts.errors.queue_busy'));

    expect($request->fresh()->status)->toBe(PostStatus::PendingApproval);
});

test('rejecting while another approval holds the post is a conflict', function () {
    $request = approvalApiRequest($this);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
    Cache::partialMock()->shouldReceive('lock')->with("post-approval:{$request->id}", PostApproval::LOCK_SECONDS)->andReturn($lock);

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route('api.posts.reject', $request))
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertJsonPath('message', __('posts.errors.queue_busy'));

    expect($request->fresh()->status)->toBe(PostStatus::PendingApproval);
});

test('approving or rejecting with the reviewed version decides the post', function (string $route, PostStatus $decided) {
    $request = approvalApiRequest($this);

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route($route, $request), ['expected_version' => $request->contentVersion()])
        ->assertOk()
        ->assertJsonPath('status', $decided->value);
})->with([
    'approve' => ['api.posts.approve', PostStatus::Scheduled],
    'reject' => ['api.posts.reject', PostStatus::Draft],
]);

test('approving or rejecting a post that changed since it was reviewed is a conflict', function (string $route) {
    $request = approvalApiRequest($this);
    $reviewed = $request->contentVersion();
    Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [EventType::PostApproved->value, EventType::PostRejected->value],
    ]);
    $request->update(['content' => 'Changed after the review']);

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route($route, $request), ['expected_version' => $reviewed])
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson([
            'message' => __('posts.approvals.errors.changed'),
            'error' => 'version_mismatch',
            'content_version' => $request->fresh()->contentVersion(),
        ]);

    expect($request->fresh())
        ->status->toBe(PostStatus::PendingApproval)
        ->approved_at->toBeNull();
    Queue::assertNotPushed(DispatchWebhook::class);
})->with(['api.posts.approve', 'api.posts.reject']);

test('the reviewed version must be a short string', function (string $route, mixed $version) {
    $request = approvalApiRequest($this);

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route($route, $request), ['expected_version' => $version])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expected_version');

    expect($request->fresh()->status)->toBe(PostStatus::PendingApproval);
})->with(['api.posts.approve', 'api.posts.reject'])->with([
    'a list' => [['abc']],
    'a number' => [123],
    'too long' => [str_repeat('a', 65)],
]);

test('a decision sent with a request id is recorded with the version it decided', function (string $route, PostStatus $decided, ApprovalDecision $decision) {
    $request = approvalApiRequest($this);
    $reviewed = $request->contentVersion();

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route($route, $request), ['request_id' => 'echo:wa-1.A_b'])
        ->assertOk()
        ->assertJsonPath('id', $request->id)
        ->assertJsonPath('status', $decided->value)
        ->assertJsonPath('replayed', false)
        ->assertJsonMissingPath('recorded_content_version');

    expect($request->approvalActions()->sole())
        ->request_id->toBe('echo:wa-1.A_b')
        ->action->toBe($decision)
        ->user_id->toBe($this->owner->id)
        ->content_version->toBe($reviewed);
})->with([
    'approve' => ['api.posts.approve', PostStatus::Scheduled, ApprovalDecision::Approved],
    'reject' => ['api.posts.reject', PostStatus::Draft, ApprovalDecision::Rejected],
]);

test('retrying a decided request replays it without changing the post again', function (string $route, PostStatus $decided, EventType $event) {
    Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [EventType::PostApproved->value, EventType::PostRejected->value],
    ]);
    $request = approvalApiRequest($this);
    $reviewed = $request->contentVersion();

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route($route, $request), ['request_id' => 'echo-1', 'expected_version' => $reviewed])
        ->assertOk();
    $decidedPost = $request->fresh();

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route($route, $request), ['request_id' => 'echo-1', 'expected_version' => $reviewed])
        ->assertOk()
        ->assertJsonPath('id', $request->id)
        ->assertJsonPath('status', $decided->value)
        ->assertJsonPath('replayed', true)
        ->assertJsonPath('recorded_content_version', $reviewed);

    $fields = ['status', 'scheduled_at', 'schedule_mode', 'approved_by', 'approved_at', 'updated_at'];
    expect($request->fresh()->only($fields))->toEqual($decidedPost->only($fields))
        ->and($request->approvalActions()->count())->toBe(1)
        ->and(Queue::pushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === $event->value))->toHaveCount(1);
})->with([
    'approve' => ['api.posts.approve', PostStatus::Scheduled, EventType::PostApproved],
    'reject' => ['api.posts.reject', PostStatus::Draft, EventType::PostRejected],
]);

test('a retry is replayed before the pending and version checks', function (string $route) {
    $request = approvalApiRequest($this);

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route($route, $request), ['request_id' => 'echo-1'])
        ->assertOk();

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route($route, $request), ['request_id' => 'echo-1', 'expected_version' => 'not-the-version'])
        ->assertOk()
        ->assertJsonPath('replayed', true);
})->with(['api.posts.approve', 'api.posts.reject']);

test('a new request for a post another approver already decided is not pending and records nothing', function (string $route) {
    $request = approvalApiRequest($this);
    ApprovePost::execute($request, workspaceMember($this->workspace, 'admin'));

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route($route, $request), ['request_id' => 'echo-2'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('post')
        ->assertJsonPath('error', 'not_pending');

    expect(PostApprovalAction::query()->count())->toBe(0);
})->with(['api.posts.approve', 'api.posts.reject']);

test('reusing a request id for the other decision is a conflict', function (string $first, string $second, PostStatus $decided) {
    $request = approvalApiRequest($this);

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route($first, $request), ['request_id' => 'echo-1'])
        ->assertOk();

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route($second, $request), ['request_id' => 'echo-1'])
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson([
            'message' => __('posts.approvals.errors.request_conflict'),
            'error' => 'request_id_conflict',
        ]);

    expect($request->fresh()->status)->toBe($decided)
        ->and($request->approvalActions()->count())->toBe(1);
})->with([
    'approve, then reject' => ['api.posts.approve', 'api.posts.reject', PostStatus::Scheduled],
    'reject, then approve' => ['api.posts.reject', 'api.posts.approve', PostStatus::Draft],
]);

test('a refused decision records nothing, so its request id can still decide the post', function (string $route, PostStatus $decided) {
    $request = approvalApiRequest($this);
    $reviewed = $request->contentVersion();
    $request->update(['content' => 'Changed after the review']);

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route($route, $request), ['request_id' => 'echo-1', 'expected_version' => $reviewed])
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertJsonPath('error', 'version_mismatch');

    expect(PostApprovalAction::query()->count())->toBe(0);

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route($route, $request), ['request_id' => 'echo-1', 'expected_version' => $request->fresh()->contentVersion()])
        ->assertOk()
        ->assertJsonPath('status', $decided->value)
        ->assertJsonPath('replayed', false);
})->with([
    'approve' => ['api.posts.approve', PostStatus::Scheduled],
    'reject' => ['api.posts.reject', PostStatus::Draft],
]);

test('the same request id decides different posts independently', function () {
    $first = approvalApiRequest($this);
    $second = approvalApiRequest($this);

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route('api.posts.approve', $first), ['request_id' => 'echo-1'])
        ->assertOk()
        ->assertJsonPath('replayed', false);

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route('api.posts.reject', $second), ['request_id' => 'echo-1'])
        ->assertOk()
        ->assertJsonPath('replayed', false);

    expect($first->fresh()->status)->toBe(PostStatus::Scheduled)
        ->and($second->fresh()->status)->toBe(PostStatus::Draft)
        ->and(PostApprovalAction::query()->count())->toBe(2);
});

test('the request id must be a short key of letters, digits and : . _ -', function (string $route, mixed $requestId) {
    $request = approvalApiRequest($this);

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route($route, $request), ['request_id' => $requestId])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('request_id');

    expect($request->fresh()->status)->toBe(PostStatus::PendingApproval)
        ->and(PostApprovalAction::query()->count())->toBe(0);
})->with(['api.posts.approve', 'api.posts.reject'])->with([
    'a list' => [['abc']],
    'a number' => [123],
    'too long' => [str_repeat('a', 101)],
    'a space' => ['echo 1'],
    'a slash' => ['echo/1'],
]);
