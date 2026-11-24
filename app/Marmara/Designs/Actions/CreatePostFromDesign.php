<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Actions;

use App\Actions\Post\CreatePosts;
use App\Actions\Post\HostInlineMedia;
use App\Enums\Media\Type as MediaType;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Marmara\Designs\Drive\DriveClient;
use App\Marmara\Designs\Drive\DriveException;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignActivity;
use App\Marmara\Designs\Models\DesignMedia;
use App\Marmara\Designs\Posts\DesignPostLinks;
use App\Marmara\Designs\Support\DesignTypes;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\HeicConverter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Gönderi oluştur": the original is downloaded from Drive on the server and
 * becomes a draft post, through the same path as the Echo media bridge's
 * `POST /api/uploads` + upload_token (a temporary upload of the workspace,
 * then HostInlineMedia + CreatePosts, which validate it for each channel).
 *
 * The file goes through TryPost's normal upload normalization
 * (Workspace::addMediaFromPath, the same as addMedia()): a JPEG is stored
 * byte for byte, PNG, WebP and HEIC become JPEG at quality 100 (visually
 * lossless; the Instagram Graph API takes only JPEG images, and the
 * publishers' resizing is tested on that path), videos and PDFs are streamed
 * unchanged (with the video length read). A Google Drawing is exported as
 * PNG and so becomes a JPEG too. The untouched original stays available
 * through "Orijinali indir" and Drive.
 *
 * Several designs (picked on the page, or a series) become one post per
 * channel with all of them as media, in order. Each design is linked to
 * the posts (DesignPostLinks), so they turn Kullanıldı once a post goes out.
 */
class CreatePostFromDesign
{
    public function __construct(
        private readonly DriveClient $drive,
        private readonly DesignPostLinks $links,
    ) {}

    /**
     * @param  list<string>  $socialAccountIds
     * @return Collection<int, Post>
     *
     * @throws ValidationException
     */
    public function execute(Design $design, User $user, array $socialAccountIds, string $content = ''): Collection
    {
        return $this->executeMany(new EloquentCollection([$design]), $user, $socialAccountIds, $content);
    }

    /**
     * One draft per channel with every design as media, in the given order
     * (a carousel where the channel takes one). CreatePosts checks each
     * channel's media rules (count, types) as for any post.
     *
     * @param  EloquentCollection<int, Design>  $designs
     * @param  list<string>  $socialAccountIds
     * @return Collection<int, Post>
     *
     * @throws ValidationException
     */
    public function executeMany(EloquentCollection $designs, User $user, array $socialAccountIds, string $content = ''): Collection
    {
        $workspace = $designs->first()?->workspace;

        if ($workspace === null) {
            throw ValidationException::withMessages(['design_ids' => __('marmara-designs::designs.post.needs_design')]);
        }

        $accounts = SocialAccount::query()->where('workspace_id', $workspace->id)->whereIn('id', $socialAccountIds)->get();

        if ($accounts->isEmpty()) {
            throw ValidationException::withMessages(['social_account_ids' => __('marmara-designs::designs.post.needs_channel')]);
        }

        $tokens = [];

        foreach ($designs->values() as $design) {
            [$media, $version] = $this->upload($design);
            $media->issueUploadToken();
            // The post's rows (this one moved, the other channels' copies) are
            // linked from it, and carry the version that was downloaded.
            DesignMedia::remember($design, $media->id, $version);
            $tokens[] = ['upload_token' => $media->upload_token];
        }

        $hosted = HostInlineMedia::forPost($workspace, MediaType::cases(), ['media' => $tokens]);

        $posts = CreatePosts::execute($workspace, $user, [
            'status' => PostStatus::Draft->value,
            'content' => $content,
            'media' => $hosted['media'],
            'label_ids' => [],
            'created_via' => CreatedVia::Web,
            'destinations' => $accounts->map(fn (SocialAccount $account): array => ['social_account_id' => $account->id])->values()->all(),
        ]);

        foreach ($posts as $post) {
            foreach ($designs->values() as $position => $design) {
                $this->links->link($design, $post, $position, $user);
            }

            $this->links->groupPost($post->id);
        }

        foreach ($designs as $design) {
            DesignActivity::record($design, $user, 'post_created', ['post_ids' => $posts->pluck('id')->all()]);
        }

        return $posts;
    }

    /**
     * The design's file as a temporary upload of the workspace, and the
     * version it is (Design::approvalVersion(): the md5 of the downloaded
     * bytes for a binary file, so a Drive change the sync has not seen yet
     * is not mistaken for the stored one; the head revision of a Google file).
     *
     * @return array{0: Media, 1: string|null}
     */
    private function upload(Design $design): array
    {
        $workspace = $design->workspace;
        $mime = $design->isGoogleNative() ? DesignTypes::DRAWING_EXPORT_MIME : $design->mime_type;
        $type = HeicConverter::isHeicMime($mime) ? MediaType::Image : MediaType::fromMime($mime);

        if ($type === null || ! DesignTypes::supported($design->mime_type)) {
            throw ValidationException::withMessages(['design' => __('marmara-designs::designs.post.unsupported')]);
        }

        if ($design->size !== null && $design->size > $type->maxSizeInBytes()) {
            throw ValidationException::withMessages(['design' => __('marmara-designs::designs.post.too_large', ['size' => $type->maxSizeInMb()])]);
        }

        try {
            $bytes = $design->isGoogleNative()
                ? $this->drive->export($design->drive_file_id, $mime)
                : $this->drive->download($design->drive_file_id);
        } catch (DriveException) {
            throw ValidationException::withMessages(['design' => __('marmara-designs::designs.post.download_failed')]);
        }

        if (strlen($bytes) > $type->maxSizeInBytes()) {
            throw ValidationException::withMessages(['design' => __('marmara-designs::designs.post.too_large', ['size' => $type->maxSizeInMb()])]);
        }

        $extension = DesignTypes::extensionFor($mime);
        $filename = Str::limit($design->displayTitle(), 180, '').".{$extension}";

        $base = (string) tempnam(sys_get_temp_dir(), 'marmara-design-');
        $temporary = "{$base}.{$extension}";

        try {
            file_put_contents($temporary, $bytes);

            $version = ! $design->isGoogleNative() && $design->md5 !== null ? md5($bytes) : $design->approvalVersion();

            return [$workspace->addMediaFromPath($temporary, $filename, Media::COLLECTION_UPLOADS, [], null, $mime), $version];
        } finally {
            @unlink($temporary);
            @unlink($base);
        }
    }
}
