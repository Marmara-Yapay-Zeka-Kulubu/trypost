<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a design's activity log: added, uploaded, status_changed,
 * tags_changed, commented, comment_edited, comment_deleted, voted, unvoted,
 * version_linked, version_unlinked, post_created.
 *
 * @property int $id
 * @property string $design_id
 * @property string|null $user_id
 * @property string $type
 * @property array<string, mixed>|null $data
 * @property CarbonImmutable $created_at
 */
class DesignActivity extends Model
{
    protected $table = 'marmara_design_activities';

    protected $fillable = ['design_id', 'user_id', 'type', 'data'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function record(Design $design, ?User $user, string $type, array $data = []): self
    {
        return self::query()->create([
            'design_id' => $design->id,
            'user_id' => $user?->id,
            'type' => $type,
            'data' => $data === [] ? null : $data,
        ]);
    }
}
