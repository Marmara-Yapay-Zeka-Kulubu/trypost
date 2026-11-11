<?php

declare(strict_types=1);

namespace App\Actions\Post\Approval;

use App\Enums\Post\ApprovalDecision;
use App\Enums\Post\Status as PostStatus;
use App\Exceptions\Post\ApprovalRequestConflictException;
use App\Exceptions\Post\PostVersionMismatchException;
use App\Models\Post;
use App\Models\PostApprovalAction;
use App\Models\User;
use App\Support\PostApproval;
use Illuminate\Support\Facades\DB;

class RejectPost
{
    /**
     * Sends a pending post back to drafts. Its requested time stays on the draft.
     * With `$expectedVersion` (a Post::contentVersion() the approver saw), a
     * post that changed since is refused without being touched.
     *
     * With `$requestId` (an idempotency key), the rejection is recorded with the
     * status change, and a retry of that request changes nothing and returns
     * the recorded action as `replayed`, even once the post is no longer
     * pending.
     *
     * @return array{post: Post, replayed: PostApprovalAction|null}
     *
     * @throws PostVersionMismatchException
     * @throws ApprovalRequestConflictException
     */
    public static function execute(Post $post, User $approver, ?string $expectedVersion = null, ?string $requestId = null): array
    {
        return PostApproval::locked($post, function () use ($post, $approver, $expectedVersion, $requestId): array {
            $replayed = PostApproval::replay($post, ApprovalDecision::Rejected, $requestId);

            if ($replayed !== null) {
                return ['post' => $post, 'replayed' => $replayed];
            }

            return PostApproval::whilePending($post, function () use ($post, $approver, $expectedVersion, $requestId): array {
                PostApproval::assertVersion($post, $expectedVersion);

                $rejectedVersion = $post->contentVersion();
                $requester = $post->approvalRequester();

                DB::transaction(function () use ($post, $approver, $requestId, $rejectedVersion, $requester): void {
                    $post->update([
                        'status' => PostStatus::Draft,
                        'schedule_mode' => null,
                        ...PostApproval::transition(PostStatus::PendingApproval, PostStatus::Draft, $approver),
                    ]);

                    PostApproval::record($post, ApprovalDecision::Rejected, $requestId, $approver, $rejectedVersion);

                    NotifyApprovalDecision::execute($post, ApprovalDecision::Rejected, $approver, $requester);
                });

                return ['post' => $post, 'replayed' => null];
            });
        });
    }
}
