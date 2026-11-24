<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Jobs;

use App\Enums\Media\Type as MediaType;
use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignActivity;
use App\Marmara\Designs\Models\DesignMedia;
use App\Marmara\Designs\Posts\DesignPostLinks;
use App\Marmara\Designs\Posts\RecordDesignPublication;
use App\Marmara\Designs\Support\DesignSource;
use App\Marmara\Designs\Support\DesignStatus;
use App\Marmara\Designs\Support\DesignTypes;
use App\Marmara\Designs\Upload\DesignUploader;
use App\Marmara\Designs\Upload\DesignUploadUnavailable;
use App\Models\Media;
use App\Models\Post;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * A post went out with images or videos that are not in the gallery (a
 * photo sent on WhatsApp, a file uploaded in the composer): each one goes to
 * Drive through DesignUploader into "Paylaşılanlar/<YYYY-MM>", as Kullanıldı,
 * credited to the post's member (post.user; source whatsapp when Hermes made
 * the post, else site), and is linked to the post. Several of them form a
 * series.
 *
 * A file already in the gallery (its media row, or the row it was copied
 * from, is known: DesignMedia) is only linked. So the same post sent to
 * Instagram and LinkedIn (one row per channel, the second copied from the
 * first or both from the same upload) gives one design per file, linked to
 * both posts; a lock per post group keeps two channels' jobs from uploading
 * the same file at the same time.
 *
 * Never touches the post: a failure is logged (and retried with backoff
 * while Drive answers "failed"); no Drive upload permission, the module off
 * or `auto_add.enabled` off skip it.
 */
class AddPublishedMediaToDesigns implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly string $postId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(DesignsConfig $config, DesignUploader $uploader, DesignPostLinks $links, RecordDesignPublication $publication): void
    {
        if (! $config->enabled() || ! $config->autoAddPublished()) {
            return;
        }

        $post = Post::query()->with(['user'])->find($this->postId);

        if ($post === null || ! $post->publish_status?->wentOut() || $config->workspace()?->id !== $post->workspace_id) {
            return;
        }

        $rows = $this->mediaOf($post);

        if ($rows->isEmpty()) {
            return;
        }

        if (! $uploader->available()) {
            Log::info('Marmara designs: published media not added to the gallery, Drive upload permission is missing', ['post' => $post->id]);

            return;
        }

        try {
            Cache::lock('marmara-designs:auto-add:'.($post->post_group_id ?? $post->id), 900)
                ->block(60, fn () => $this->add($post, $rows, $config, $uploader, $links));
        } catch (LockTimeoutException) {
            $this->release(60);

            return;
        }

        $links->groupPost($post->id);
        $publication->record($post);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Marmara designs: published media could not be added to the gallery', ['post' => $this->postId, 'error' => Str::limit($exception->getMessage(), 300)]);
    }

    /**
     * @param  Collection<int, Media>  $rows
     */
    private function add(Post $post, Collection $rows, DesignsConfig $config, DesignUploader $uploader, DesignPostLinks $links): void
    {
        $publishedAt = CarbonImmutable::parse($post->published_at ?? now());
        $count = $rows->count();

        foreach ($rows->values() as $index => $row) {
            $lineage = DesignMedia::lineage($row);
            $origin = DesignMedia::originFor($lineage);
            $design = $origin === null ? null : Design::query()->where('workspace_id', $row->workspace_id)->find($origin['design_id']);
            $version = $origin['version'] ?? null;

            if ($design === null) {
                $design = $this->upload($post, $row, $index, $count, $publishedAt, $config, $uploader);

                if ($design === null) {
                    continue;
                }

                // The design was made from this file: its first version.
                $version = $design->approvalVersion();
            }

            // This row and the file it was copied from: the other channels' copies find it.
            DesignMedia::remember($design, [$row->id, $lineage[array_key_last($lineage)]], $version);
            $links->link($design, $post, $index, $post->user);
        }
    }

    /**
     * @throws DesignUploadUnavailable while Drive answers "failed" (retried)
     */
    private function upload(Post $post, Media $row, int $index, int $count, CarbonImmutable $publishedAt, DesignsConfig $config, DesignUploader $uploader): ?Design
    {
        if (! DesignTypes::supported($row->mime_type) || DesignTypes::isGoogleNative((string) $row->mime_type)) {
            return null;
        }

        $temporary = $this->localCopy($row);

        if ($temporary === null) {
            Log::info('Marmara designs: a published file is no longer stored, not added to the gallery', ['post' => $post->id, 'media' => $row->id]);

            return null;
        }

        $member = $post->user;
        $source = filled($post->getAttribute('agent_user_id')) ? DesignSource::WhatsApp : DesignSource::Site;

        try {
            $design = $uploader->upload(
                $temporary,
                $config->autoAddFolder($publishedAt),
                $this->title($post, $publishedAt, $index, $count),
                (string) ($member?->name ?? 'TryPost'),
                $member,
                $row->mime_type,
                $source,
                $member,
            );
        } catch (DesignUploadUnavailable $unavailable) {
            if ($unavailable->reason === 'failed') {
                throw $unavailable;
            }

            Log::info('Marmara designs: a published file was not added to the gallery', ['post' => $post->id, 'media' => $row->id, 'reason' => $unavailable->reason]);

            return null;
        } finally {
            @unlink($temporary);
        }

        // Already out: Kullanıldı from the start, without a status change line.
        $design->forceFill([
            'status' => DesignStatus::Used,
            'status_changed_at' => CarbonImmutable::now(),
            'approved_version' => $design->approvalVersion(),
        ])->save();

        DesignActivity::record($design, $member, 'auto_added', [
            'post_id' => $post->id,
            'platform' => $post->platform?->value,
            'platform_label' => $post->platform?->label(),
            'name' => $member?->name,
        ]);

        return $design;
    }

    /**
     * The post's own images and videos (not thread replies, not covers), in
     * the post's order.
     *
     * @return Collection<int, Media>
     */
    private function mediaOf(Post $post): Collection
    {
        $ids = collect($post->media ?? [])
            ->filter(fn (mixed $item): bool => is_array($item) && is_string(data_get($item, 'id')))
            ->pluck('id')
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $rows = Media::query()->where('post_id', $post->id)->whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(fn (string $id): ?Media => $rows->get($id))
            ->filter(fn (?Media $row): bool => $row !== null && in_array($row->type, [MediaType::Image, MediaType::Video], true))
            ->values();
    }

    /**
     * The first 60 characters of the post's text, else "<platform> paylaşımı
     * <date>"; "(2/3)" after it for the files of a carousel.
     */
    private function title(Post $post, CarbonImmutable $publishedAt, int $index, int $count): string
    {
        $text = Str::squish(html_entity_decode(strip_tags((string) $post->content), ENT_QUOTES | ENT_HTML5));
        $title = $text !== ''
            ? Str::limit($text, 60, '')
            // In Turkish like the folder, whatever the queue worker's locale: the gallery is the club's.
            : (string) __('marmara-designs::designs.auto_add.title', ['platform' => $post->platform?->label() ?? 'TryPost', 'date' => $publishedAt->format('d.m.Y')], 'tr');

        return $count > 1 ? sprintf('%s (%d/%d)', $title, $index + 1, $count) : $title;
    }

    /**
     * The stored file (local disk or object storage) as a local temporary
     * file with the right extension.
     */
    private function localCopy(Media $row): ?string
    {
        $stream = filled($row->path) ? Storage::readStream($row->path) : null;

        if (! is_resource($stream)) {
            return null;
        }

        $base = (string) tempnam(sys_get_temp_dir(), 'marmara-design-auto-');
        $path = $base.'.'.DesignTypes::extensionFor((string) $row->mime_type);
        @unlink($base);
        $target = fopen($path, 'wb');

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($target);
            fclose($stream);
        }

        return $path;
    }
}
