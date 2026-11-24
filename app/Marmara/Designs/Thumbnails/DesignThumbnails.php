<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Thumbnails;

use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Drive\DriveClient;
use App\Marmara\Designs\Drive\DriveException;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignRevision;
use App\Marmara\Designs\Support\DesignTypes;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * The thumbnail proxy's source. Drive thumbnails need the service account's
 * token, so the browser never sees them directly: the current content comes
 * from Drive's own thumbnail (thumbnailLink at =s{size}); an older revision,
 * or an image Drive made no thumbnail for, is downloaded and scaled here.
 * Either way the result is re-encoded as a JPEG (about 50 KB at 400 px) and
 * kept in the bounded ThumbnailCache, keyed by the content version, so an
 * edit never shows a stale picture.
 *
 * Only a real picture is cached. Drive makes a video's thumbnail some
 * minutes after the upload (no thumbnailLink until then), so "no thumbnail
 * yet" is a null that is never stored: the next request asks Drive again.
 */
class DesignThumbnails
{
    public function __construct(
        private readonly DesignsConfig $config,
        private readonly DriveClient $drive,
        private readonly ThumbnailCache $cache,
    ) {}

    public function size(int $requested): int
    {
        $sizes = $this->config->thumbnailSizes();
        sort($sizes);

        foreach ($sizes as $size) {
            if ($requested <= $size) {
                return $size;
            }
        }

        return end($sizes);
    }

    /**
     * @return array{bytes: string, mime_type: string}|null
     */
    public function get(Design $design, int $size, ?DesignRevision $revision = null): ?array
    {
        $size = $this->size($size);
        $current = $revision === null || $revision->revision_id === $design->head_revision_id;
        $version = $current ? $design->contentVersion() : ($revision->md5 ?? $revision->revision_id);
        $key = implode(':', [$design->id, $current ? 'head' : $revision->revision_id, $version, $size]);

        if (($cached = $this->cache->get($key)) !== null) {
            return $cached;
        }

        try {
            $thumbnail = $current ? $this->current($design, $size) : $this->revision($design, $revision, $size);
        } catch (DriveException) {
            return null;
        }

        if ($thumbnail !== null) {
            $this->cache->put($key, $design->id, $thumbnail['bytes'], $thumbnail['mime_type']);
        }

        return $thumbnail;
    }

    /**
     * @return array{bytes: string, mime_type: string}|null
     */
    private function current(Design $design, int $size): ?array
    {
        $bytes = $this->drive->thumbnail($design->drive_file_id, $size);

        if ($bytes !== null) {
            $encoded = $this->encode($bytes, $size);

            if ($encoded !== null) {
                return $encoded;
            }

            // GD could not open it: pass it on only if it is a picture at all
            // (never an HTML or JSON answer cached as image/jpeg).
            $mime = self::sniff($bytes);

            return $mime !== null ? ['bytes' => $bytes, 'mime_type' => $mime] : null;
        }

        if (! $this->decodable($design->mime_type) || ($design->size ?? PHP_INT_MAX) > $this->config->thumbnailSourceMaxBytes()) {
            return null;
        }

        return $this->encode($this->drive->download($design->drive_file_id), $size);
    }

    /**
     * @return array{bytes: string, mime_type: string}|null
     */
    private function revision(Design $design, DesignRevision $revision, int $size): ?array
    {
        $mime = $revision->mime_type ?? $design->mime_type;

        if (! $this->decodable($mime) || ($revision->size ?? PHP_INT_MAX) > $this->config->thumbnailSourceMaxBytes()) {
            return null;
        }

        return $this->encode($this->drive->download($design->drive_file_id, $revision->revision_id), $size);
    }

    /**
     * What GD can open here (HEIC, PDF and video only get Drive's thumbnail).
     */
    private function decodable(?string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) && ! DesignTypes::isGoogleNative($mime);
    }

    /**
     * @return array{bytes: string, mime_type: string}|null
     */
    private function encode(string $bytes, int $size): ?array
    {
        try {
            $image = (new ImageManager(new Driver))->decodeBinary($bytes)->scaleDown($size, $size);

            return ['bytes' => (string) $image->encode(new JpegEncoder(quality: $this->config->thumbnailQuality(), progressive: true)), 'mime_type' => 'image/jpeg'];
        } catch (Throwable) {
            return null;
        }
    }

    private static function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            default => null,
        };
    }
}
