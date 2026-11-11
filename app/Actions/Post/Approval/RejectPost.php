<?php

declare(strict_types=1);

namespace App\Actions\Post\Approval;

use App\Enums\Post\ApprovalDecision;
use App\Enums\Post\Status as PostStatus;
use App\Exceptions\Post\PostVersionMismatchException;
use App\Models\Post;
use App\Models\User;
use App\Support\PostApproval;

class RejectPost
{
    /**
     * Sends a pending post back to drafts. Its requested time stays on the draft.
     * With `$expectedVersion` (a Post::contentVersion() the approver saw), a
     * post that changed since is refused without being touched.
     *
     * @throws PostVersionMismatchException
     */
    public static function execute(Post $post, User $approver, ?string $expectedVersion = null): Post
    {
        return PostApproval::whilePending($post, function () use ($post, $approver, $expectedVersion): Post {
            PostApproval::assertVersion($post, $expectedVersion);

            $requester = $post->approvalRequester();

            $post->update([
                'status' => PostStatus::Draft,
                'schedule_mode' => null,
                ...PostApproval::transition(PostStatus::PendingApproval, PostStatus::Draft, $approver),
            ]);

            NotifyApprovalDecision::execute($post, ApprovalDecision::Rejected, $approver, $requester);

            return $post;
        });
    }
}
