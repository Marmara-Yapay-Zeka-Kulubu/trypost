<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Models;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One comment or direct message read from a network.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $social_account_id
 * @property string $provider
 * @property string $platform
 * @property string $kind
 * @property string $external_id
 * @property string|null $thread_id
 * @property string|null $parent_external_id
 * @property string|null $post_external_id
 * @property string|null $post_id
 * @property string|null $post_excerpt
 * @property string|null $author_external_id
 * @property string|null $author_name
 * @property string|null $author_handle
 * @property string|null $author_avatar_url
 * @property string|null $text
 * @property string|null $permalink
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $hidden_at
 * @property bool $can_reply
 * @property CarbonImmutable|null $reply_window_ends_at
 * @property array<string, mixed>|null $meta
 * @property-read SocialAccount|null $socialAccount
 * @property-read Post|null $post
 */
class InboxItem extends Model
{
    use HasUuids;

    public const string KIND_COMMENT = 'comment';

    public const string KIND_MESSAGE = 'message';

    public const array KINDS = [self::KIND_COMMENT, self::KIND_MESSAGE];

    protected $table = 'marmara_inbox_items';

    protected $fillable = [
        'workspace_id',
        'social_account_id',
        'provider',
        'platform',
        'kind',
        'external_id',
        'thread_id',
        'parent_external_id',
        'post_external_id',
        'post_id',
        'post_excerpt',
        'author_external_id',
        'author_name',
        'author_handle',
        'author_avatar_url',
        'text',
        'permalink',
        'received_at',
        'read_at',
        'archived_at',
        'hidden_at',
        'can_reply',
        'reply_window_ends_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'hidden_at' => 'immutable_datetime',
            'reply_window_ends_at' => 'immutable_datetime',
            'can_reply' => 'boolean',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<SocialAccount, $this>
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return HasMany<InboxReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(InboxReply::class, 'inbox_item_id');
    }

    /**
     * @param  Builder<InboxItem>  $query
     * @return Builder<InboxItem>
     */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'unread' => $query->whereNull('read_at')->whereNull('archived_at'),
            'read' => $query->whereNotNull('read_at')->whereNull('archived_at'),
            'archived' => $query->whereNotNull('archived_at'),
            'all' => $query,
            default => $query->whereNull('archived_at'),
        };
    }

    public function status(): string
    {
        return match (true) {
            $this->archived_at !== null => 'archived',
            $this->read_at !== null => 'read',
            default => 'unread',
        };
    }

    /**
     * The items of the same conversation or comment thread, oldest first.
     *
     * @return Builder<InboxItem>
     */
    public function threadItems(): Builder
    {
        return self::query()
            ->where('social_account_id', $this->social_account_id)
            ->where('provider', $this->provider)
            ->where('thread_id', $this->thread_id ?? $this->external_id)
            ->orderBy('received_at');
    }

    /**
     * Whether a reply can still be sent now: the network allows replies to
     * this item and, for a DM, the messaging window is still open.
     */
    public function replyWindowClosed(): bool
    {
        return $this->reply_window_ends_at !== null && $this->reply_window_ends_at->isPast();
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }
}
