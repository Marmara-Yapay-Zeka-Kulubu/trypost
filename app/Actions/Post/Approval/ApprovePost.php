<?php

declare(strict_types=1);

namespace App\Actions\Post\Approval;

use App\Actions\Post\UpdatePost;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\ApprovalDecision;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Exceptions\Post\ApprovalRequestConflictException;
use App\Exceptions\Post\PostVersionMismatchException;
use App\Models\Post;
use App\Models\PostApprovalAction;
use App\Models\User;
use App\Support\PostApproval;
use App\Support\PostStatusRules;
use Illuminate\Validation\ValidationException;

class ApprovePost
{
    /**
     * Schedules a pending post exactly as its author asked (queue position or
     * time), or at the approver's `scheduled_at` / `publish_now` choice.
     *
     * With `$expectedVersion` (a Post::contentVersion() the approver saw), a
     * post that changed since is refused without being touched.
     *
     * With `$requestId` (an idempotency key), the approval is recorded with the
     * status change, and a retry of that request changes nothing and returns
     * the recorded action as `replayed`, even once the post is no longer
     * pending.
     *
     * @param  array{publish_now?: bool, scheduled_at?: string|null}  $choice
     * @return array{post: Post, action: PostAction|null, replayed: PostApprovalAction|null}
     *
     * @throws PostVersionMismatchException
     * @throws ApprovalRequestConflictException
     */
    public static function execute(Post $post, User $approver, array $choice = [], ?string $expectedVersion = null, ?string $requestId = null): array
    {
        return PostApproval::locked($post, function () use ($post, $approver, $choice, $expectedVersion, $requestId): array {
            $replayed = PostApproval::replay($post, ApprovalDecision::Approved, $requestId);

            if ($replayed !== null) {
                return ['post' => $post, 'action' => null, 'replayed' => $replayed];
            }

            return PostApproval::whilePending($post, function () use ($post, $approver, $choice, $expectedVersion, $requestId): array {
                PostApproval::assertVersion($post, $expectedVersion);

                $approvedVersion = $post->contentVersion();
                $data = match (true) {
                    (bool) data_get($choice, 'publish_now') => ['status' => PostStatus::Publishing->value],
                    filled(data_get($choice, 'scheduled_at')) => [
                        'status' => PostStatus::Scheduled->value,
                        'scheduled_at' => data_get($choice, 'scheduled_at'),
                    ],
                    $post->schedule_mode === ScheduleMode::Queue => [
                        'status' => PostStatus::Scheduled->value,
                        'queue' => ($post->approval_queue_position ?? QueuePosition::Next)->value,
                    ],
                    $post->scheduled_at?->isFuture() === true => [
                        'status' => PostStatus::Scheduled->value,
                        'scheduled_at' => $post->scheduled_at->toIso8601String(),
                    ],
                    default => throw ValidationException::withMessages(['scheduled_at' => __('posts.approvals.errors.time_passed')]),
                };

                PostStatusRules::assertStoredPostPublishable($post);

                $result = UpdatePost::execute(
                    $post->workspace,
                    $post,
                    $data,
                    $approver,
                    fn (Post $approved) => PostApproval::record($approved, ApprovalDecision::Approved, $requestId, $approver, $approvedVersion),
                );

                return [...$result, 'replayed' => null];
            });
        });
    }
}
