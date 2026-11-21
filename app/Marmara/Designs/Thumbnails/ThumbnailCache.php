<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Thumbnails;

use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Models\DesignThumbnail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Thumbnails on the local disk, bounded: the index (marmara_design_thumbnails)
 * keeps each file's size and last use, and every write evicts the least
 * recently used files until the cache is back under 90 % of
 * MARMARA_DESIGNS_THUMBNAIL_CACHE_MB. Originals are never stored here.
 */
class ThumbnailCache
{
    /** A hit refreshes last_accessed_at at most this often (one write per minute per file). */
    private const int TOUCH_EVERY_SECONDS = 60;

    public function __construct(private readonly DesignsConfig $config) {}

    /**
     * @return array{bytes: string, mime_type: string}|null
     */
    public function get(string $key): ?array
    {
        $row = DesignThumbnail::query()->where('cache_key', $key)->first();

        if ($row === null) {
            return null;
        }

        $path = $this->absolute($row->path);

        if (! is_file($path)) {
            $row->delete();

            return null;
        }

        if ($row->last_accessed_at->lt(CarbonImmutable::now()->subSeconds(self::TOUCH_EVERY_SECONDS))) {
            $row->forceFill(['last_accessed_at' => CarbonImmutable::now()])->save();
        }

        return ['bytes' => (string) file_get_contents($path), 'mime_type' => $row->mime_type];
    }

    public function put(string $key, ?string $designId, string $bytes, string $mimeType): void
    {
        $hash = sha1($key);
        $relative = substr($hash, 0, 2).'/'.$hash.'.'.($mimeType === 'image/png' ? 'png' : ($mimeType === 'image/webp' ? 'webp' : 'jpg'));
        $path = $this->absolute($relative);

        try {
            File::ensureDirectoryExists(dirname($path), 0755);
            file_put_contents($path, $bytes, LOCK_EX);
        } catch (Throwable) {
            // A full or read-only disk only costs the cache, never the page.
            return;
        }

        DesignThumbnail::query()->updateOrCreate(['cache_key' => $key], [
            'design_id' => $designId,
            'path' => $relative,
            'mime_type' => $mimeType,
            'bytes' => strlen($bytes),
            'last_accessed_at' => CarbonImmutable::now(),
        ]);

        $this->evict();
    }

    public function totalBytes(): int
    {
        return (int) DesignThumbnail::query()->sum('bytes');
    }

    /**
     * Least recently used first, until the cache is under 90 % of its limit.
     */
    public function evict(): int
    {
        $max = $this->config->thumbnailCacheMaxBytes();
        $total = $this->totalBytes();

        if ($total <= $max) {
            return 0;
        }

        $target = (int) floor($max * 0.9);
        $evicted = 0;

        while ($total > $target) {
            $batch = DesignThumbnail::query()->orderBy('last_accessed_at')->orderBy('id')->limit(200)->get();

            if ($batch->isEmpty()) {
                break;
            }

            foreach ($batch as $row) {
                if ($total <= $target) {
                    break;
                }

                @unlink($this->absolute($row->path));
                $row->delete();
                $total -= $row->bytes;
                $evicted++;
            }
        }

        return $evicted;
    }

    private function absolute(string $relative): string
    {
        return $this->config->thumbnailPath().'/'.ltrim($relative, '/');
    }
}
