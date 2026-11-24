<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Models;

use App\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A TryPost media row that holds a design's file: made by "Gönderi
 * oluştur", handed to add-design-tool, or added to the gallery from a
 * published post. A post's copy of such a row is found through its
 * `meta.copied_from` (SyncOwnedMedia), see lineage().
 *
 * @property int $id
 * @property string $design_id
 * @property string $media_id
 */
class DesignMedia extends Model
{
    /** How far a chain of copies (`meta.copied_from`) is followed. */
    private const int MAX_LINEAGE = 10;

    protected $table = 'marmara_design_media';

    protected $fillable = ['design_id', 'media_id'];

    /**
     * @return BelongsTo<Design, $this>
     */
    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    /**
     * Remembers that these media rows hold the design's file.
     *
     * @param  list<string>|string  $mediaIds
     */
    public static function remember(Design $design, array|string $mediaIds): void
    {
        $now = now();
        $rows = collect((array) $mediaIds)->filter()->unique()
            ->map(fn (string $id): array => ['design_id' => $design->id, 'media_id' => $id, 'created_at' => $now, 'updated_at' => $now])
            ->values()->all();

        if ($rows !== []) {
            self::query()->insertOrIgnore($rows);
        }
    }

    /**
     * The row's id, then the rows it was copied from, nearest first (the
     * last one is the original file, which may no longer exist).
     *
     * @return list<string>
     */
    public static function lineage(Media $media): array
    {
        $ids = [$media->id];
        $from = data_get($media->meta, 'copied_from');

        while (is_string($from) && $from !== '' && ! in_array($from, $ids, true) && count($ids) < self::MAX_LINEAGE) {
            $ids[] = $from;
            // value() skips the cast: the column comes back as JSON text.
            $meta = Media::query()->whereKey($from)->value('meta');
            $from = data_get(is_string($meta) ? json_decode($meta, true) : $meta, 'copied_from');
        }

        return $ids;
    }

    /**
     * The design the media row (or the file it was copied from) holds.
     *
     * @param  list<string>|null  $lineage
     */
    public static function designFor(Media $media, ?array $lineage = null): ?Design
    {
        $lineage ??= self::lineage($media);
        $known = self::query()->whereIn('media_id', $lineage)->pluck('design_id', 'media_id');

        foreach ($lineage as $id) {
            if ($known->has($id)) {
                return Design::query()->where('workspace_id', $media->workspace_id)->find($known->get($id));
            }
        }

        return null;
    }
}
