<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reply sent (or tried) from TryPost. `request_id` makes the send
 * idempotent per item: the same request id answers the stored result.
 *
 * @property string $id
 * @property string $inbox_item_id
 * @property string $workspace_id
 * @property string $social_account_id
 * @property string|null $user_id
 * @property string $request_id
 * @property string $text
 * @property string $status
 * @property string|null $external_id
 * @property string|null $error
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $created_at
 */
class InboxReply extends Model
{
    use HasUuids;

    /** The request to the network has not answered yet. */
    public const string STATUS_SENDING = 'sending';

    public const string STATUS_SENT = 'sent';

    public const string STATUS_FAILED = 'failed';

    protected $table = 'marmara_inbox_replies';

    protected $fillable = [
        'inbox_item_id',
        'workspace_id',
        'social_account_id',
        'user_id',
        'request_id',
        'text',
        'status',
        'external_id',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<InboxItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class, 'inbox_item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
