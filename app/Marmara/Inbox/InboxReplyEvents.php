<?php

declare(strict_types=1);

namespace App\Marmara\Inbox;

use App\Enums\Webhook\EventType;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Models\InboxReply;
use App\Models\Workspace;
use App\Services\WebhookService;

/**
 * `inbox.reply_sent`: a reply reached the network, whoever sent it (Echo,
 * Hermes through MCP, the panel), so Echo can tell the others who were
 * notified of the item. SendInboxReply calls sent() only for the one update
 * that moved the reply from `sending` to `sent`, so the event fires once per
 * reply; replays of the same request_id never get here.
 */
class InboxReplyEvents
{
    public function __construct(private readonly WebhookService $webhooks) {}

    public function sent(InboxReply $reply): void
    {
        $reply->loadMissing(['item.workspace', 'user']);
        $item = $reply->item;
        $workspace = $item?->workspace;

        if ($item === null || $workspace === null) {
            return;
        }

        // The reply is out already: a failing dispatch is reported, never thrown at the sender.
        rescue(fn () => $this->webhooks->dispatch($workspace, EventType::InboxReplySent, self::payload($reply, $item, $workspace)));
    }

    /**
     * @return array{workspace: array{id: string, name: string|null}, item: array<string, mixed>, reply: array{id: string, text: string, sent_at: string|null, request_id: string}, sent_by: array{id: string, name: string|null}|null, via: string}
     */
    public static function payload(InboxReply $reply, InboxItem $item, Workspace $workspace): array
    {
        $reply->loadMissing('user');

        return [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'item' => InboxItemPayload::item($item),
            'reply' => [
                'id' => $reply->id,
                'text' => $reply->text,
                'sent_at' => $reply->sent_at?->toIso8601String(),
                'request_id' => $reply->request_id,
            ],
            'sent_by' => $reply->user === null ? null : ['id' => $reply->user->id, 'name' => $reply->user->name],
            'via' => $reply->via ?? InboxReply::VIA_API,
        ];
    }
}
