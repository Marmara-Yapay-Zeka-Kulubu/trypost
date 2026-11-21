<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A free tag on a design (lowercase, trimmed, up to 50 characters).
 *
 * @property int $id
 * @property string $design_id
 * @property string $tag
 */
class DesignTag extends Model
{
    protected $table = 'marmara_design_tags';

    protected $fillable = ['design_id', 'tag'];

    /**
     * @return BelongsTo<Design, $this>
     */
    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }
}
