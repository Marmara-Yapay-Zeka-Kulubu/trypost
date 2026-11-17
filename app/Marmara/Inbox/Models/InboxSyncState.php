<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Models;

use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where the sync of one channel, provider and kind stands: the provider's
 * cursor (saved after every page, so a stopped run resumes), its status and
 * the back-off.
 *
 * @property int $id
 * @property string $social_account_id
 * @property string $provider
 * @property string $kind
 * @property array<string, mixed>|null $cursor
 * @property string $status
 * @property string|null $status_detail
 * @property CarbonImmutable|null $blocked_until
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $last_attempt_at
 */
class InboxSyncState extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_OK = 'ok';

    public const string STATUS_NEEDS_PERMISSION = 'needs_permission';

    public const string STATUS_RATE_LIMITED = 'rate_limited';

    public const string STATUS_AUTHENTICATION = 'authentication';

    public const string STATUS_UNSUPPORTED = 'unsupported';

    public const string STATUS_ERROR = 'error';

    protected $table = 'marmara_inbox_sync_states';

    protected $fillable = [
        'social_account_id',
        'provider',
        'kind',
        'cursor',
        'status',
        'status_detail',
        'blocked_until',
        'last_synced_at',
        'last_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'cursor' => 'array',
            'blocked_until' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SocialAccount, $this>
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function isBlocked(): bool
    {
        return $this->blocked_until !== null && $this->blocked_until->isFuture();
    }
}
