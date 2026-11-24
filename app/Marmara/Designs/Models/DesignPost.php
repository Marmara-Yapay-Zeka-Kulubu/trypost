<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Models;

use App\Models\Post;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A design on a post (one post per channel). `position` is the design's
 * place in the post's media; `published_at` is set once the post went out
 * and that was recorded on the design (RecordDesignPublication).
 *
 * @property int $id
 * @property string $design_id
 * @property string $post_id
 * @property string $workspace_id
 * @property int $position
 * @property string|null $linked_by_user_id
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable $created_at
 */
class DesignPost extends Model
{
    protected $table = 'marmara_design_posts';

    protected $fillable = ['design_id', 'post_id', 'workspace_id', 'position', 'linked_by_user_id', 'published_at'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
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
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }
}
