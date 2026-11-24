<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Posts;

use App\Enums\Post\PublishStatus;
use App\Marmara\Designs\DesignEvents;
use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Jobs\AddPublishedMediaToDesigns;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignActivity;
use App\Marmara\Designs\Models\DesignPost;
use App\Marmara\Designs\Support\DesignStatus;
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

        foreach ($linkIds as $linkId) {
            $recorded += DB::transaction(function () use ($linkId, $post): int {
                $link = DesignPost::query()->whereKey($linkId)->lockForUpdate()->first();

                if ($link === null || $link->published_at !== null) {
                    return 0;
                }

                $publishedAt = CarbonImmutable::parse($post->published_at ?? now());
                $link->forceFill(['published_at' => $publishedAt])->save();

                $design = Design::query()->whereKey($link->design_id)->lockForUpdate()->first();

                if ($design === null) {
                    return 0;
                }

                $this->markUsed($design, $post);

                DesignActivity::record($design, $post->user, 'post_published', [
                    'post_id' => $post->id,
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
     */
    private function markUsed(Design $design, Post $post): void
    {
        $from = $design->status;

        if ($from === DesignStatus::Used || $from === DesignStatus::Archived) {
            return;
        }

        $design->forceFill([
            'status' => DesignStatus::Used,
            'status_changed_at' => CarbonImmutable::now(),
            'approved_version' => $design->approvalVersion(),
            'status_reason' => null,
            'changed_after_use_at' => null,
        ])->save();

        DesignActivity::record($design, $post->user, 'status_changed', ['from' => $from->value, 'to' => DesignStatus::Used->value, 'reason' => 'post_published']);
        $this->events->statusChanged($design, $post->user, $from, 'post_published');
    }

    private function applies(Post $post): bool
    {
        return $post->publish_status instanceof PublishStatus
            && $post->publish_status->wentOut()
            && $this->config->enabled()
            && $this->config->workspace()?->id === $post->workspace_id;
    }
}
