<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Post\ApprovalDecision;
use Database\Factories\PostApprovalActionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An approve or reject sent with a `request_id` (an idempotency key from an
 * outside tool, a chat bot say) that changed the post. It is written in the
 * transaction that decides the post, so it exists exactly when the decision
 * was applied, and a retry of the same request is answered from it.
 */
class PostApprovalAction extends Model
{
    /** @use HasFactory<PostApprovalActionFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'post_id',
        'request_id',
        'action',
        'user_id',
        'content_version',
    ];

    protected function casts(): array
    {
        return [
            'action' => ApprovalDecision::class,
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
