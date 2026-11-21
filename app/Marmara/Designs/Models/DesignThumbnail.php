<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Index of one cached thumbnail file on the local disk, for the LRU
 * eviction (ThumbnailCache).
 *
 * @property int $id
 * @property string $cache_key
 * @property string|null $design_id
 * @property string $path
 * @property string $mime_type
 * @property int $bytes
 * @property CarbonImmutable $last_accessed_at
 */
class DesignThumbnail extends Model
{
    protected $table = 'marmara_design_thumbnails';

    protected $fillable = [
        'cache_key',
        'design_id',
        'path',
        'mime_type',
        'bytes',
        'last_accessed_at',
    ];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'last_accessed_at' => 'immutable_datetime',
        ];
    }
}
