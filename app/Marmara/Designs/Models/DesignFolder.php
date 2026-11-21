<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A folder inside the shared folder. Its `path` ("Bahar Şenliği/Afişler")
 * is the event / collection of the designs in it; the root has path ''.
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $drive_id
 * @property string|null $parent_drive_id
 * @property string $name
 * @property string $path
 * @property bool $is_root
 * @property CarbonImmutable|null $removed_at
 */
class DesignFolder extends Model
{
    protected $table = 'marmara_design_folders';

    protected $fillable = [
        'workspace_id',
        'drive_id',
        'parent_drive_id',
        'name',
        'path',
        'is_root',
        'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_root' => 'boolean',
            'removed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<DesignFolder>  $query
     * @return Builder<DesignFolder>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    public function childPath(string $name): string
    {
        return $this->path === '' ? $name : "{$this->path}/{$name}";
    }
}
