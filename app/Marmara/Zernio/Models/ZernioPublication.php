<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Models;

use App\Models\Post;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The current Zernio attempt of a TryPost post. `first_attempt_at` and
 * `idempotency_key` belong to the attempt and never change during it; only a
 * definitive failure starts a new attempt.
 *
 * @property int $id
 * @property string $post_id
 * @property string $zernio_account_id
 * @property string|null $zernio_post_id
 * @property string $idempotency_key
 * @property CarbonInterface $first_attempt_at
 * @property CarbonInterface|null $last_attempt_at
 * @property string $last_status
 * @property string|null $last_error
 */
class ZernioPublication extends Model
{
    /** The attempt exists but no create request has left yet. */
    public const string STATUS_PREPARING = 'preparing';

    /** The create request's outcome is unknown (timeout, 5xx, conflict). */
    public const string STATUS_UNKNOWN = 'unknown';

    /** Zernio refused the create request: nothing exists on Zernio. */
    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_PROCESSING = 'processing';

    public const string STATUS_PUBLISHED = 'published';

    public const string STATUS_FAILED = 'failed';

    /** Not resolvable automatically: an operator must check Zernio. */
    public const string STATUS_NEEDS_MANUAL_CHECK = 'needs_manual_check';

    protected $table = 'marmara_zernio_publications';

    protected $fillable = [
        'post_id',
        'zernio_account_id',
        'zernio_post_id',
        'idempotency_key',
        'first_attempt_at',
        'last_attempt_at',
        'last_status',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'first_attempt_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Whether this attempt is over without anything on TikTok, so the next
     * publish may create a new Zernio post.
     */
    public function isDefinitivelyFailed(): bool
    {
        return in_array($this->last_status, [self::STATUS_FAILED, self::STATUS_REJECTED], true);
    }

    /**
     * The external reference sent with the create request (`tags` and
     * `metadata`), to find the post again when the answer was lost.
     */
    public function reference(): string
    {
        return 'trypost-'.$this->idempotency_key;
    }
}
