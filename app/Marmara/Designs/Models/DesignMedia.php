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
 * `version` is the design's version the file was made from
 * (Design::approvalVersion() at the time); a copy inherits it from the row it
 * was copied from. Null for rows made before it was kept.
 *
 * @property int $id
 * @property string $design_id
 * @property string $media_id
 * @property string|null $version
 */
class DesignMedia extends Model
{
    /** How far a chain of copies (`meta.copied_from`) is followed. */
    private const int MAX_LINEAGE = 10;

    protected $table = 'marmara_design_media';

    protected $fillable = ['design_id', 'media_id', 'version'];

    /**
     * @return BelongsTo<Design, $this>
     */
    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    /**
     * Remembers that these media rows hold the design's file, made from
     * `$version` of it when known. A row already known keeps what it has.
     *
     * @param  list<string>|string  $mediaIds
     */
    public static function remember(Design $design, array|string $mediaIds, ?string $version = null): void
    {
        $now = now();
        $rows = collect((array) $mediaIds)->filter()->unique()
            ->map(fn (string $id): array => ['design_id' => $design->id, 'media_id' => $id, 'version' => $version, 'created_at' => $now, 'updated_at' => $now])
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
        $origin = self::originFor($lineage ?? self::lineage($media));

        return $origin === null ? null : Design::query()->where('workspace_id', $media->workspace_id)->find($origin['design_id']);
    }

    /**
     * The design the nearest known row of the lineage holds, and the version
     * the file was made from: the nearest version known for that design
     * along the chain (a copy inherits its source's), null when none is.
     *
     * @param  list<string>  $lineage
     * @return array{design_id: string, version: string|null}|null
     */
    public static function originFor(array $lineage): ?array
    {
        $known = self::query()->whereIn('media_id', $lineage)->get(['design_id', 'media_id', 'version'])->keyBy('media_id');
        $designId = null;
        $version = null;

        foreach ($lineage as $id) {
            $row = $known->get($id);

            if ($row === null || ($designId !== null && $row->design_id !== $designId)) {
                continue;
            }

            $designId ??= $row->design_id;
            $version = $row->version;

            if (filled($version)) {
                break;
            }
        }

        return $designId === null ? null : ['design_id' => $designId, 'version' => filled($version) ? $version : null];
    }
}
