<?php

declare(strict_types=1);

namespace App\Marmara\Designs;

use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignComment;
use App\Marmara\Designs\Support\DesignStatus;
use App\Models\User;
use App\Models\Workspace;

/**
 * Who may do what with a design. Every member of the design's workspace sees,
 * tags, comments, votes and links versions; Onaylandı / Kullanıldı (and
 * moving a design away from them) need the right to approve or publish
 * posts (WorkspacePolicy::approvePosts, the same right as approving a
 * post); only a comment's author edits or deletes it.
 *
 * Registered with Gate::policy() for Design, plus the
 * `set-design-status` gate (DesignsServiceProvider::STATUS_ABILITY) that the
 * page, the API and MCP all check, like the inbox's reply gate.
 */
class DesignPolicy
{
    public function view(User $user, Design $design): bool
    {
        $workspace = $design->workspace;

        return $workspace instanceof Workspace
            && app(DesignsConfig::class)->isDesignsWorkspace($workspace)
            && $user->can('view', $workspace);
    }

    public function comment(User $user, Design $design): bool
    {
        return $this->view($user, $design);
    }

    public function vote(User $user, Design $design): bool
    {
        return $this->view($user, $design);
    }

    public function tag(User $user, Design $design): bool
    {
        return $this->view($user, $design);
    }

    public function linkVersion(User $user, Design $design): bool
    {
        return $this->view($user, $design);
    }

    public function setStatus(User $user, Design $design, DesignStatus|string $status): bool
    {
        $target = $status instanceof DesignStatus ? $status : DesignStatus::tryFrom($status);

        if ($target === null || ! $this->view($user, $design)) {
            return false;
        }

        if (! $target->needsApprover() && ! $design->status->needsApprover()) {
            return true;
        }

        return $this->canApprove($user, $design);
    }

    public function createPost(User $user, Design $design): bool
    {
        return $this->view($user, $design) && $user->can('createPost', $design->workspace);
    }

    public function updateComment(User $user, DesignComment $comment): bool
    {
        return $comment->user_id !== null && $comment->user_id === $user->id && $comment->design !== null && $this->view($user, $comment->design);
    }

    public function deleteComment(User $user, DesignComment $comment): bool
    {
        return $this->updateComment($user, $comment);
    }

    public function canApprove(User $user, Design $design): bool
    {
        return $design->workspace instanceof Workspace && $user->can('approvePosts', $design->workspace);
    }
}
