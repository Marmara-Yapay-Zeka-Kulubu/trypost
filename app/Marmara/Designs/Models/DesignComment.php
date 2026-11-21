<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's comment on a design. Only its author edits or deletes it.
 *
 * @property string $id
 * @property string $design_id
 * @property string|null $user_id
 * @property string $body
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable $created_at
 */
class DesignComment extends Model
{
    use HasUuids;

    protected $table = 'marmara_design_comments';

    protected $fillable = ['design_id', 'user_id', 'body', 'edited_at'];

    protected function casts(): array
    {
        return [
            'edited_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Design, $this>
     */
    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
