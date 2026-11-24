<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Posts;

use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignMedia;
use App\Marmara\Designs\Models\DesignPost;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Which posts a design went on, and its series.
 *
 * A post is linked to a design whenever one of its media rows holds the
 * design's file (DesignMedia): "Gönderi oluştur", a post an agent made with
 * the media_id add-design-tool answered, a channel's copy of either, or a
 * file the gallery took from a published post. The link is made from the
 * media row's own `saved` event (DesignsServiceProvider), so every path
 * that puts media on a post (CreatePosts, an edit, a duplicate) is covered
 * without touching TryPost's post code.
 *
 * Several designs on one post form a series (`design_set_id`), shown as one
 * card in the gallery.
 */
class DesignPostLinks
{
    public function __construct(private readonly DesignsConfig $config) {}

    /**
     * A media row was saved: when it now belongs to a post and holds a
     * design's file, the post is linked to the design. Runs inside the
     * save's transaction, so the link rolls back with it.
     */
    public function mediaSaved(Media $media): void
    {
        if ($media->post_id === null || ! ($media->wasRecentlyCreated || $media->wasChanged('post_id'))) {
            return;
        }

        if (! $this->config->enabled()) {
            return;
        }

        $origin = DesignMedia::originFor(DesignMedia::lineage($media));
        $design = $origin === null ? null : Design::query()->where('workspace_id', $media->workspace_id)->find($origin['design_id']);

        if ($design === null) {
            return;
        }

        // The post's own row (with the version of the file it copies), so a
        // later copy of it is found in one step.
        DesignMedia::remember($design, $media->id, $origin['version']);
        $user = Auth::user();
        $this->link($design, $media->post_id, (int) $media->order, $user instanceof User ? $user : null);
        $this->groupPost($media->post_id);
    }

    /**
     * Links the design to the post (once; the first position and linker win).
     */
    public function link(Design $design, Post|string $post, int $position, ?User $linkedBy = null): DesignPost
    {
        $postId = $post instanceof Post ? $post->id : $post;

        return DesignPost::query()->firstOrCreate(
            ['design_id' => $design->id, 'post_id' => $postId],
            ['workspace_id' => $design->workspace_id, 'position' => max(0, $position), 'linked_by_user_id' => $linkedBy?->id],
        );
    }

    /**
     * Several designs on one post are a series: the ones not in a series yet
     * join the series of the first one that is (by position), or a new one.
     * A design already in another series stays there.
     */
    public function groupPost(string $postId): void
    {
        $links = DesignPost::query()->where('post_id', $postId)->orderBy('position')->orderBy('id')->get(['design_id', 'position']);

        if ($links->pluck('design_id')->unique()->count() < 2) {
            return;
        }

        $designs = Design::query()->whereIn('id', $links->pluck('design_id'))->get()->keyBy('id');
        $setId = $links->map(fn (DesignPost $link): ?string => $designs->get($link->design_id)?->design_set_id)->filter()->first()
            ?? (string) Str::uuid();

        foreach ($links as $link) {
            $design = $designs->get($link->design_id);

            if ($design !== null && $design->design_set_id === null) {
                $design->forceFill(['design_set_id' => $setId, 'set_position' => $link->position])->save();
            }
        }
    }
}
