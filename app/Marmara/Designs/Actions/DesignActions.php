<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Actions;

use App\Marmara\Designs\DesignEvents;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignActivity;
use App\Marmara\Designs\Models\DesignComment;
use App\Marmara\Designs\Models\DesignTag;
use App\Marmara\Designs\Models\DesignVote;
use App\Marmara\Designs\Support\DesignStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The club's own changes to a design, shared by the page, the API and MCP.
 * Authorization is the caller's job (DesignPolicy / the status gate); every
 * change writes one activity line; comments and status changes fire the
 * design.commented / design.status_changed webhooks (DesignEvents).
 */
class DesignActions
{
    public const int MAX_TAGS = 20;

    public function setStatus(Design $design, User $user, DesignStatus $status): bool
    {
        $from = $design->status;

        if ($from === $status) {
            return false;
        }

        $design->forceFill(['status' => $status, 'status_changed_at' => CarbonImmutable::now()])->save();
        DesignActivity::record($design, $user, 'status_changed', ['from' => $from->value, 'to' => $status->value]);
        $this->statusChanged($design, $user, $from);

        return true;
    }

    /**
     * Replaces the design's tags (normalized: trimmed, lowercase, no '#',
     * up to 50 characters, at most 20).
     *
     * @param  list<string>  $tags
     * @return list<string>
     */
    public function syncTags(Design $design, User $user, array $tags): array
    {
        $normalized = self::normalizeTags($tags);
        $current = $design->tags()->pluck('tag')->all();

        if ($normalized === $current) {
            return $normalized;
        }

        DB::transaction(function () use ($design, $normalized): void {
            DesignTag::query()->where('design_id', $design->id)->whereNotIn('tag', $normalized)->delete();

            foreach ($normalized as $tag) {
                DesignTag::query()->firstOrCreate(['design_id' => $design->id, 'tag' => $tag]);
            }
        });

        DesignActivity::record($design, $user, 'tags_changed', ['added' => array_values(array_diff($normalized, $current)), 'removed' => array_values(array_diff($current, $normalized))]);

        return $normalized;
    }

    public function comment(Design $design, User $user, string $body): DesignComment
    {
        $comment = DB::transaction(function () use ($design, $user, $body): DesignComment {
            $comment = DesignComment::query()->create(['design_id' => $design->id, 'user_id' => $user->id, 'body' => trim($body)]);
            $design->forceFill([
                'comments_count' => DesignComment::query()->where('design_id', $design->id)->count(),
                'last_commented_at' => $comment->created_at,
            ])->save();

            return $comment;
        });

        DesignActivity::record($design, $user, 'commented', ['comment_id' => $comment->id]);
        $this->commented($design, $comment, $user);

        return $comment;
    }

    public function editComment(DesignComment $comment, User $user, string $body): DesignComment
    {
        $comment->forceFill(['body' => trim($body), 'edited_at' => CarbonImmutable::now()])->save();
        DesignActivity::record($comment->design, $user, 'comment_edited', ['comment_id' => $comment->id]);

        return $comment;
    }

    public function deleteComment(DesignComment $comment, User $user): void
    {
        $design = $comment->design;

        DB::transaction(function () use ($comment, $design): void {
            $comment->delete();
            $design->forceFill([
                'comments_count' => DesignComment::query()->where('design_id', $design->id)->count(),
                'last_commented_at' => DesignComment::query()->where('design_id', $design->id)->max('created_at'),
            ])->save();
        });

        DesignActivity::record($design, $user, 'comment_deleted', ['comment_id' => $comment->id]);
    }

    /**
     * One vote per member: voting twice changes nothing.
     */
    public function vote(Design $design, User $user): bool
    {
        try {
            $vote = DesignVote::query()->firstOrCreate(['design_id' => $design->id, 'user_id' => $user->id]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        if (! $vote->wasRecentlyCreated) {
            return false;
        }

        $this->recountVotes($design);
        DesignActivity::record($design, $user, 'voted');

        return true;
    }

    public function unvote(Design $design, User $user): bool
    {
        $deleted = DesignVote::query()->where('design_id', $design->id)->where('user_id', $user->id)->delete() > 0;

        if ($deleted) {
            $this->recountVotes($design);
            DesignActivity::record($design, $user, 'unvoted');
        }

        return $deleted;
    }

    /**
     * "Bu tasarım, X'in yeni sürümü": puts the design (and the family it
     * already had) into X's family. Versions are ordered by Drive's
     * createdTime; the newest is the one the gallery shows.
     */
    public function linkVersion(Design $design, User $user, Design $previous): void
    {
        if ($design->is($previous) || ($design->family_id !== null && $design->family_id === $previous->family_id)) {
            throw ValidationException::withMessages(['previous_id' => __('marmara-designs::designs.errors.link_self')]);
        }

        DB::transaction(function () use ($design, $previous): void {
            $familyId = $previous->family_id ?? $previous->id;

            if ($previous->family_id === null) {
                $previous->forceFill(['family_id' => $familyId])->save();
            }

            if ($design->family_id !== null) {
                Design::query()->where('workspace_id', $design->workspace_id)->where('family_id', $design->family_id)->update(['family_id' => $familyId]);
            }

            $design->forceFill(['family_id' => $familyId])->save();
            self::refreshFamily($design->workspace_id, $familyId);
        });

        DesignActivity::record($design, $user, 'version_linked', ['previous_id' => $previous->id, 'previous_title' => $previous->title]);
    }

    public function unlinkVersion(Design $design, User $user): void
    {
        $familyId = $design->family_id;

        if ($familyId === null) {
            return;
        }

        DB::transaction(function () use ($design, $familyId): void {
            $design->forceFill(['family_id' => null, 'family_latest' => true])->save();
            self::refreshFamily($design->workspace_id, $familyId);
        });

        DesignActivity::record($design, $user, 'version_unlinked');
    }

    /**
     * The newest active member of a family is the one shown; a family of one
     * is no family.
     */
    public static function refreshFamily(string $workspaceId, string $familyId): void
    {
        $members = Design::query()->where('workspace_id', $workspaceId)->where('family_id', $familyId)
            ->orderByRaw('removed_at is not null')->orderByDesc('drive_created_at')->orderByDesc('created_at')->get();

        if ($members->count() <= 1) {
            Design::query()->whereIn('id', $members->pluck('id'))->update(['family_id' => null, 'family_latest' => true]);

            return;
        }

        $latest = $members->first(fn (Design $member): bool => $member->removed_at === null) ?? $members->first();

        Design::query()->whereIn('id', $members->pluck('id'))->where('id', '!=', $latest->id)->update(['family_latest' => false]);
        Design::query()->whereKey($latest->id)->update(['family_latest' => true]);
    }

    /**
     * @param  list<mixed>  $tags
     * @return list<string>
     */
    public static function normalizeTags(array $tags): array
    {
        $normalized = collect($tags)
            ->filter(fn (mixed $tag): bool => is_string($tag))
            ->map(fn (string $tag): string => Str::limit(mb_strtolower(trim(ltrim(trim($tag), '#'))), 50, ''))
            ->filter(fn (string $tag): bool => $tag !== '')
            ->unique()
            ->sort()
            ->values()
            ->take(self::MAX_TAGS)
            ->all();

        return array_values($normalized);
    }

    private function recountVotes(Design $design): void
    {
        $design->forceFill(['votes_count' => DesignVote::query()->where('design_id', $design->id)->count()])->save();
    }

    private function statusChanged(Design $design, User $user, DesignStatus $from): void
    {
        app(DesignEvents::class)->statusChanged($design, $user, $from);
    }

    private function commented(Design $design, DesignComment $comment, User $user): void
    {
        app(DesignEvents::class)->commented($design, $comment, $user);
    }
}
