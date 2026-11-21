<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Drive revision of a design's file (files.revisions.list), kept forever
 * on Drive when `keep_forever` is set.
 *
 * @property int $id
 * @property string $design_id
 * @property string $revision_id
 * @property string|null $mime_type
 * @property int|null $size
 * @property string|null $md5
 * @property CarbonImmutable|null $modified_at
 * @property string|null $modifier_name
 * @property string|null $modifier_email
 * @property string|null $original_filename
 * @property bool $keep_forever
 * @property string|null $keep_error
 */
class DesignRevision extends Model
{
    protected $table = 'marmara_design_revisions';

    protected $fillable = [
        'design_id',
        'revision_id',
        'mime_type',
        'size',
        'md5',
        'modified_at',
        'modifier_name',
        'modifier_email',
        'original_filename',
        'keep_forever',
        'keep_error',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'modified_at' => 'immutable_datetime',
            'keep_forever' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Design, $this>
     */
    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }
}
