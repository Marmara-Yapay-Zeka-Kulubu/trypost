<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Providers;

/**
 * One page of a provider's read: the items found, the cursor to save and
 * whether another page is waiting.
 */
final readonly class FetchResult
{
    /**
     * @param  list<InboxItemData>  $items
     * @param  array<string, mixed>  $cursor
     */
    public function __construct(
        public array $items,
        public array $cursor,
        public bool $hasMore = false,
    ) {}
}
