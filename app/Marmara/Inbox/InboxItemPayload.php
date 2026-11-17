<?php

declare(strict_types=1);

namespace App\Marmara\Inbox;

use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Models\InboxReply;

/**
 * The item shape of the Echo/Hermes contract: the `data.item` of the
 * `inbox.item_received` webhook and the items of the REST API and MCP tools.
 *
 * `post.id` is the TryPost post id when TryPost published the post, else
 * the network's own post id; `post` is null for a DM.
 */
class InboxItemPayload
{
    /**
     * @return array{id: string, platform: string, kind: string, author: array{name: string|null, handle: string|null}, text: string|null, permalink: string|null, post: array{id: string, excerpt: string|null}|null, received_at: string, can_reply: bool, reply_window_ends_at: string|null}
     */
    public static function item(InboxItem $item): array
    {
        $postId = $item->post_id ?? $item->post_external_id;

        return [
            'id' => $item->id,
            'platform' => $item->platform,
            'kind' => $item->kind,
            'author' => [
                'name' => $item->author_name,
                'handle' => $item->author_handle,
            ],
            'text' => $item->text,
            'permalink' => $item->permalink,
            'post' => $item->kind === InboxItem::KIND_COMMENT && $postId !== null
                ? ['id' => $postId, 'excerpt' => $item->post_excerpt]
                : null,
            'received_at' => $item->received_at->toIso8601String(),
            'can_reply' => $item->can_reply && ! $item->replyWindowClosed(),
            'reply_window_ends_at' => $item->reply_window_ends_at?->toIso8601String(),
        ];
    }

    /**
     * The contract item plus where it stands in TryPost, its thread and the
     * replies sent from TryPost (the detail endpoint and get-inbox-item-tool).
     *
     * @return array<string, mixed>
     */
    public static function detail(InboxItem $item): array
    {
        $item->loadMissing(['replies', 'socialAccount']);

        return [
            ...self::item($item),
            'status' => $item->status(),
            'channel' => $item->socialAccount === null ? null : [
                'id' => $item->socialAccount->id,
                'name' => $item->socialAccount->display_name,
                'username' => $item->socialAccount->username,
            ],
            'thread' => $item->threadItems()
                ->whereKeyNot($item->id)
                ->limit(50)
                ->get()
                ->map(fn (InboxItem $other): array => self::item($other))
                ->all(),
            'replies' => $item->replies
                ->sortBy('created_at')
                ->map(fn (InboxReply $reply): array => self::reply($reply))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{id: string, status: string, text: string, error: string|null, created_at: string|null}
     */
    public static function reply(InboxReply $reply): array
    {
        return [
            'id' => $reply->id,
            'status' => $reply->status,
            'text' => $reply->text,
            'error' => $reply->error,
            'created_at' => $reply->created_at?->toIso8601String(),
        ];
    }
}
