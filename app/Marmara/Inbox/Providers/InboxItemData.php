<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Providers;

use Carbon\CarbonImmutable;

/**
 * An item as a provider read it, before it is stored. `fromSelf` marks the
 * channel's own comments and messages (our replies), which are never stored
 * as inbox items. `aliases` are other ids the same item may already be
 * stored under (an id a path used before its canonical one); an item found
 * by an alias is known, and its row is moved to the canonical id.
 */
final readonly class InboxItemData
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $aliases
     */
    public function __construct(
        public string $kind,
        public string $externalId,
        public CarbonImmutable $receivedAt,
        public ?string $text,
        public ?string $threadId = null,
        public ?string $parentExternalId = null,
        public ?string $postExternalId = null,
        public ?string $postExcerpt = null,
        public ?string $authorId = null,
        public ?string $authorName = null,
        public ?string $authorHandle = null,
        public ?string $authorAvatarUrl = null,
        public ?string $permalink = null,
        public bool $canReply = true,
        public ?CarbonImmutable $replyWindowEndsAt = null,
        public bool $fromSelf = false,
        public bool $hidden = false,
        public ?string $platform = null,
        public array $meta = [],
        public array $aliases = [],
    ) {}
}
