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
 * `user_id` is the member credited with the reply (the panel user, the API
 * key's user, or the member an agent replied on behalf of); `agent_user_id`
 * is the connected user whose token sent it in that last case. `via` says how
 * it was made (VIA_*).
 *
 * @property string $id
 * @property string $inbox_item_id
 * @property string $workspace_id
 * @property string $social_account_id
 * @property string|null $user_id
 * @property string|null $agent_user_id
 * @property string|null $via
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

    /** Echo's "Cevapla" flow: the REST API with an `echo-` request id. */
    public const string VIA_ECHO = 'echo';

    /** An agent (Hermes) through the MCP reply tool. */
    public const string VIA_AGENT = 'agent';

    /** The inbox page of the web panel. */
    public const string VIA_PANEL = 'panel';

    /** Any other REST API client. */
    public const string VIA_API = 'api';

    protected $table = 'marmara_inbox_replies';

    protected $fillable = [
        'inbox_item_id',
        'workspace_id',
        'social_account_id',
        'user_id',
        'agent_user_id',
        'via',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }
}
