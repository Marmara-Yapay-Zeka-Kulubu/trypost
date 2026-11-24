<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Posts;

use App\Enums\Post\PublishStatus;
use App\Marmara\Designs\DesignEvents;
use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Jobs\AddPublishedMediaToDesigns;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignActivity;
use App\Marmara\Designs\Models\DesignMedia;
use App\Marmara\Designs\Models\DesignPost;
use App\Marmara\Designs\Support\DesignStatus;
use App\Models\Media;
use App\Models\Post;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A post went out (publish_status published, or deleted on the network
 * since): each design on it becomes Kullanıldı (an archived one stays
 * archived) and gets the activity line "post_published", credited to the
 * post's member (post.user: the WhatsApp sender for Hermes' posts). Each
 * link is recorded once (`marmara_design_posts.published_at`), so a retry,
 * a second run or a re-publish adds nothing.
 *
 * Only designs still on the post count: a link whose design's file was
 * taken off the draft before it went out is dropped, not recorded. The
 * version recorded is the one the post's file was made from (DesignMedia
 * `version`), not the one Drive has by then; when they differ the design is
 * flagged "kullanıldıktan sonra değişti" like the sync does.
 *
 * Then, unless turned off, the post's own images and videos that are not
 * in the gallery yet go to Drive in a queued job (AddPublishedMediaToDesigns).
 */
class RecordDesignPublication
{
    public function __construct(
        private readonly DesignsConfig $config,
        private readonly DesignEvents $events,
    ) {}

    /**
     * Called after commit from the post's `updated` event when publish_status
     * moved into a went-out state (DesignsServiceProvider). Never throws into
     * the publish job: the caller wraps it in rescue().
     */
    public function postWentOut(Post $post): void
    {
        if (! $this->applies($post)) {
            return;
        }

        $this->record($post);

        if ($this->config->autoAddPublished()) {
            AddPublishedMediaToDesigns::dispatch($post->id);
        }
    }

    /**
     * Records the publication on every design of the post not recorded yet.
     *
     * @return int how many designs were recorded
     */
    public function record(Post $post): int
    {
        if (! $this->applies($post)) {
            return 0;
        }

        $post->loadMissing(['user']);
        $recorded = 0;
        $linkIds = DesignPost::query()->where('post_id', $post->id)->whereNull('published_at')->orderBy('position')->pluck('id');

        if ($linkIds->isEmpty()) {
            return 0;
        }

        $onPost = $this->designsOnPost($post);

        foreach ($linkIds as $linkId) {
            $recorded += DB::transaction(function () use ($linkId, $post, $onPost): int {
                $link = DesignPost::query()->whereKey($linkId)->lockForUpdate()->first();

                if ($link === null || $link->published_at !== null) {
                    return 0;
                }

                // Its file was taken off the post before it went out: not used.
                if (! array_key_exists($link->design_id, $onPost)) {
                    $link->delete();

                    return 0;
                }

                $version = $onPost[$link->design_id];
                $publishedAt = CarbonImmutable::parse($post->published_at ?? now());
                $link->forceFill(['published_at' => $publishedAt])->save();

                $design = Design::query()->whereKey($link->design_id)->lockForUpdate()->first();

                if ($design === null) {
                    return 0;
                }

                $this->markUsed($design, $post, $version);

                DesignActivity::record($design, $post->user, 'post_published', [
                    'post_id' => $post->id,
                    'version' => $version,
                    'platform' => $post->platform?->value,
                    'platform_label' => $post->platform?->label(),
                    'account' => $post->platform_name ?? $post->platform_username,
                    'published_at' => $publishedAt->toIso8601String(),
                    'by' => $post->user?->name,
                    'name' => $post->user?->name,
                ]);

                return 1;
            });
        }

        return $recorded;
    }

    /**
     * Kullanıldı, unless it is archived or already used. Shown in the log as
     * a status change by the post's member and sent as design.status_changed
     * with reason "post_published".
     *
     * Used for the version that went out (`$version`, null when unknown: the
     * current one). When the file has changed since, it is flagged
     * "kullanıldıktan sonra değişti" (changed_after_use_at and the activity
     * line changed_after_use), as the sync does for a change after use.
     */
    private function markUsed(Design $design, Post $post, ?string $version): void
    {
        $from = $design->status;

        if ($from === DesignStatus::Used || $from === DesignStatus::Archived) {
            return;
        }

        $current = $design->approvalVersion();
        $changedSince = $version !== null && $current !== null && $version !== $current;

        $design->forceFill([
            'status' => DesignStatus::Used,
            'status_changed_at' => CarbonImmutable::now(),
            'approved_version' => $version ?? $current,
            'status_reason' => null,
            'changed_after_use_at' => $changedSince ? CarbonImmutable::now() : null,
        ])->save();

        DesignActivity::record($design, $post->user, 'status_changed', ['from' => $from->value, 'to' => DesignStatus::Used->value, 'reason' => 'post_published']);
        $this->events->statusChanged($design, $post->user, $from, 'post_published');

        if ($changedSince) {
            DesignActivity::record($design, null, 'changed_after_use');
        }
    }

    /**
     * The designs whose file is on the post now, with the version each file
     * was made from (null when unknown): the post's own media rows (removed
     * ones are deleted by SyncOwnedMedia) and the items of its media list,
     * each followed through its copies to a known design file.
     *
     * @return array<string, string|null> design id => version
     */
    private function designsOnPost(Post $post): array
    {
        $listed = collect($post->media ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->flatMap(fn (array $item): array => [data_get($item, 'id'), data_get($item, 'meta.cover.id')]);
        $ids = Media::query()->where('post_id', $post->id)->pluck('id')
            ->merge($listed)
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $rows = Media::query()->whereIn('id', $ids)->get()->keyBy('id');
        $designs = [];

        foreach ($ids as $id) {
            $row = $rows->get($id);
            $origin = DesignMedia::originFor($row instanceof Media ? DesignMedia::lineage($row) : [$id]);

            if ($origin === null) {
                continue;
            }

            // A design on the post twice: the first known version wins.
            if (! array_key_exists($origin['design_id'], $designs) || $designs[$origin['design_id']] === null) {
                $designs[$origin['design_id']] = $origin['version'];
            }
        }

        return $designs;
    }

    private function applies(Post $post): bool
    {
        return $post->publish_status instanceof PublishStatus
            && $post->publish_status->wentOut()
            && $this->config->enabled()
            && $this->config->workspace()?->id === $post->workspace_id;
    }
}
