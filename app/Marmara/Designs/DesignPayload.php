<?php

declare(strict_types=1);

namespace App\Marmara\Designs;

use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignActivity;
use App\Marmara\Designs\Models\DesignComment;
use App\Marmara\Designs\Models\DesignRevision;
use App\Marmara\Designs\Posts\DesignPublications;
use App\Marmara\Designs\Support\DesignTypes;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * The one design shape for the page, the REST API, the MCP tools and the
 * design.* webhooks. Only names, never emails, leave through the API, MCP
 * and webhooks (like the post approval payloads); the page adds emails for
 * members of the workspace.
 */
class DesignPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function item(Design $design, bool $signedThumbnails = false): array
    {
        $design->loadMissing('uploader');

        return [
            'id' => $design->id,
            // The shown title: the one set in TryPost, else the Drive file name without extension.
            'title' => $design->displayTitle(),
            // The title from the Drive file name, and the one set in TryPost (null: none).
            'drive_title' => $design->title,
            'custom_title' => $design->custom_title,
            'name' => $design->name,
            'kind' => $design->kind,
            'mime_type' => $design->mime_type,
            'status' => $design->status->value,
            'status_label' => $design->status->label(),
            // content_changed: the approved file changed, so it is back in review.
            'status_reason' => $design->status_reason,
            'changed_after_use_at' => $design->changed_after_use_at?->toIso8601String(),
            'folder' => $design->folder_path,
            // The member credited with it (id null when it is only a name, e.g. "Elif (WhatsApp)").
            'uploader' => ['id' => $design->uploader?->id, 'name' => $design->uploaderLabel()],
            // site, whatsapp, drive or agent: how it got into the folder.
            'source' => $design->source->value,
            'tags' => $design->relationLoaded('tags') ? $design->tags->pluck('tag')->values()->all() : $design->tags()->pluck('tag')->all(),
            'votes' => $design->votes_count,
            'comments' => $design->comments_count,
            'last_commented_at' => $design->last_commented_at?->toIso8601String(),
            'width' => $design->width,
            'height' => $design->height,
            'size' => $design->size,
            'created_at' => $design->drive_created_at?->toIso8601String(),
            'modified_at' => $design->drive_modified_at?->toIso8601String(),
            'family_id' => $design->family_id,
            // A series: several designs of one carousel post, shown as one card.
            'set_id' => $design->design_set_id,
            'set_position' => $design->set_position,
            'removed' => $design->removed_at !== null,
            'drive_url' => $design->web_view_link,
            'url' => route('marmara.designs.index', ['design' => $design->id]),
            'thumbnail_url' => self::thumbnailUrl($design, 400, null, $signedThumbnails),
            'preview_url' => self::thumbnailUrl($design, 1600, null, $signedThumbnails),
        ];
    }

    /**
     * The item plus comments, versions and the recent activity.
     *
     * @return array<string, mixed>
     */
    public static function detail(Design $design, ?User $viewer = null, bool $signedThumbnails = false): array
    {
        $design->loadMissing(['tags', 'comments.user', 'activities.user']);

        return [
            ...self::item($design, $signedThumbnails),
            'modifier' => ['name' => $design->modifier_name],
            'comments_list' => $design->comments->map(fn (DesignComment $comment): array => self::comment($comment, $viewer))->values()->all(),
            'versions' => self::versions($design, $signedThumbnails),
            'set' => self::set($design, $signedThumbnails),
            // Where it went out, newest first (posts that were published).
            'publications' => DesignPublications::forDesign($design->id),
            'activity' => $design->activities->take(30)->map(fn (DesignActivity $activity): array => [
                'id' => $activity->id,
                'type' => $activity->type,
                'data' => $activity->data ?? [],
                'user' => $activity->user ? ['name' => $activity->user->name] : null,
                'created_at' => $activity->created_at->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function comment(DesignComment $comment, ?User $viewer = null): array
    {
        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'author' => $comment->user ? ['id' => $comment->user->id, 'name' => $comment->user->name] : null,
            'created_at' => $comment->created_at->toIso8601String(),
            'edited_at' => $comment->edited_at?->toIso8601String(),
            'mine' => $viewer !== null && $comment->user_id === $viewer->id,
        ];
    }

    /**
     * The series the design belongs to, in order; null outside a series.
     *
     * @return array{id: string, count: int, items: list<array<string, mixed>>}|null
     */
    public static function set(Design $design, bool $signed = false): ?array
    {
        if ($design->design_set_id === null) {
            return null;
        }

        $members = $design->setMembers();

        return [
            'id' => $design->design_set_id,
            'count' => $members->count(),
            'items' => $members->map(fn (Design $member): array => [
                'id' => $member->id,
                'title' => $member->displayTitle(),
                'kind' => $member->kind,
                'status' => $member->status->value,
                'thumbnail_url' => self::thumbnailUrl($member, 400, null, $signed),
            ])->values()->all(),
        ];
    }

    /**
     * Every version of the design, oldest first, labelled v1, v2, …: the
     * files linked by hand into its family (by Drive createdTime) and, within
     * each file, its Drive revisions (by time). The last revision of a file
     * is its current content.
     *
     * @return list<array<string, mixed>>
     */
    public static function versions(Design $design, bool $signed = false): array
    {
        $members = $design->family();
        $members->loadMissing(['revisions', 'uploader']);
        $versions = [];

        foreach ($members as $member) {
            $revisions = DesignTypes::isGoogleNative($member->mime_type) ? collect() : $member->revisions;
            $older = $revisions->reject(fn (DesignRevision $revision): bool => $revision->revision_id === $member->head_revision_id)->values();

            if ($member->head_revision_id === null && $older->isNotEmpty()) {
                // Without a head id the newest revision is the current content.
                $older = $older->slice(0, -1)->values();
            }

            foreach ($older as $revision) {
                $versions[] = [
                    'key' => "{$member->id}:{$revision->revision_id}",
                    'design_id' => $member->id,
                    'revision_id' => $revision->revision_id,
                    'title' => $member->displayTitle(),
                    'modified_at' => $revision->modified_at?->toIso8601String(),
                    'author' => ['name' => $revision->modifier_name],
                    'kept' => $revision->keep_forever,
                    'is_current' => false,
                    'is_this_file' => $member->is($design),
                    'thumbnail_url' => self::thumbnailUrl($member, 400, $revision, $signed),
                    'preview_url' => self::thumbnailUrl($member, 1600, $revision, $signed),
                ];
            }

            $head = $revisions->first(fn (DesignRevision $revision): bool => $revision->revision_id === $member->head_revision_id) ?? $revisions->last();
            $versions[] = [
                'key' => "{$member->id}:head",
                'design_id' => $member->id,
                'revision_id' => null,
                'title' => $member->displayTitle(),
                'modified_at' => ($member->drive_modified_at ?? $head?->modified_at)?->toIso8601String(),
                'author' => ['name' => $member->modifier_name ?? $member->uploaderLabel()],
                'kept' => $head?->keep_forever ?? false,
                'is_current' => $member->is($design),
                'is_this_file' => $member->is($design),
                'thumbnail_url' => self::thumbnailUrl($member, 400, null, $signed),
                'preview_url' => self::thumbnailUrl($member, 1600, null, $signed),
            ];
        }

        foreach ($versions as $index => $version) {
            $versions[$index]['label'] = 'v'.($index + 1);
        }

        return $versions;
    }

    /**
     * The proxy URL: the session route for the page, or a temporary signed
     * one (MARMARA_DESIGNS_SIGNED_URL_DAYS) for webhooks, the API and MCP,
     * whose readers have no TryPost session.
     */
    public static function thumbnailUrl(Design $design, int $size, ?DesignRevision $revision = null, bool $signed = false): ?string
    {
        if ($revision !== null && ! in_array($revision->mime_type ?? $design->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        $parameters = array_filter(['design' => $design->id, 'size' => $size, 'revision' => $revision?->revision_id, 'v' => $revision === null ? $design->contentVersion() : null]);

        if (! $signed) {
            return route('marmara.designs.thumbnail', $parameters);
        }

        $days = app(DesignsConfig::class)->signedUrlDays();
        // Rounded to the day, so the same design gives the same URL all day long.
        $expires = CarbonImmutable::now()->startOfDay()->addDays($days + 1);

        return URL::temporarySignedRoute('marmara.designs.thumbnail.signed', $expires, $parameters);
    }
}
