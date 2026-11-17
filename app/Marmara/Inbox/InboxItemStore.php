<?php

declare(strict_types=1);

namespace App\Marmara\Inbox;

use App\Enums\Webhook\EventType;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Models\InboxReply;
use App\Marmara\Inbox\Providers\InboxItemData;
use App\Marmara\Inbox\Providers\InboxProvider;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\WebhookService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Stores what a provider read: one row per (channel, provider, external id),
 * so a comment read twice is stored once and `inbox.item_received` fires
 * once, after the row commits. The channel's own comments and messages
 * (our replies, read back from the network) are never stored.
 */
class InboxItemStore
{
    public function __construct(
        private readonly InboxConfig $config,
        private readonly WebhookService $webhooks,
    ) {}

    /**
     * @return InboxItem|null the new item, or null when it was known or ours
     */
    public function ingest(SocialAccount $account, InboxProvider $provider, InboxItemData $data): ?InboxItem
    {
        if ($data->fromSelf || $this->isOurReply($account, $data->externalId)) {
            return null;
        }

        $existing = InboxItem::query()
            ->where('social_account_id', $account->id)
            ->where('provider', $provider->key())
            ->where('external_id', $data->externalId)
            ->first();

        if ($existing !== null) {
            $this->refresh($existing, $data);

            return null;
        }

        try {
            $item = DB::transaction(function () use ($account, $provider, $data): InboxItem {
                $item = InboxItem::query()->create([
                    'workspace_id' => $account->workspace_id,
                    'social_account_id' => $account->id,
                    'provider' => $provider->key(),
                    'platform' => $data->platform ?? $account->platform->value,
                    'kind' => $data->kind,
                    'external_id' => $data->externalId,
                    'thread_id' => $data->threadId ?? $data->externalId,
                    'parent_external_id' => $data->parentExternalId,
                    'post_external_id' => $data->postExternalId,
                    'post_id' => $this->postId($account, $data->postExternalId),
                    'post_excerpt' => $data->postExcerpt !== null ? mb_substr($data->postExcerpt, 0, 280) : null,
                    'author_external_id' => $data->authorId,
                    'author_name' => $data->authorName !== null ? mb_substr($data->authorName, 0, 255) : null,
                    'author_handle' => $data->authorHandle !== null ? mb_substr($data->authorHandle, 0, 255) : null,
                    'author_avatar_url' => $data->authorAvatarUrl,
                    'text' => $data->text,
                    'permalink' => $data->permalink,
                    'received_at' => $data->receivedAt,
                    'hidden_at' => $data->hidden ? now() : null,
                    'can_reply' => $data->canReply,
                    'reply_window_ends_at' => $data->replyWindowEndsAt,
                    'meta' => $data->meta === [] ? null : $data->meta,
                ]);

                $this->extendThreadWindow($item);

                if ($this->notifies($item)) {
                    DB::afterCommit(fn () => $this->notify($item));
                }

                return $item;
            });
        } catch (UniqueConstraintViolationException) {
            // A parallel sync stored it first; that one notified.
            return null;
        }

        return $item;
    }

    /**
     * Sends `inbox.item_received` through TryPost's webhooks (signed like
     * every other event) to the workspace's endpoints that subscribe to it.
     */
    public function notify(InboxItem $item): void
    {
        $workspace = $item->workspace;

        if ($workspace !== null) {
            $this->webhooks->dispatch($workspace, EventType::InboxItemReceived, ['item' => InboxItemPayload::item($item)]);
        }
    }

    /**
     * A new item older than notify_max_age_hours is stored silently (the
     * first sync of a channel reads its backlog).
     */
    private function notifies(InboxItem $item): bool
    {
        return $item->received_at->greaterThanOrEqualTo(CarbonImmutable::now()->subHours($this->config->notifyMaxAgeHours()));
    }

    /**
     * A new DM reopens the window for the whole conversation: every item of
     * the thread answers with the latest end.
     */
    private function extendThreadWindow(InboxItem $item): void
    {
        if ($item->kind !== InboxItem::KIND_MESSAGE || $item->reply_window_ends_at === null) {
            return;
        }

        InboxItem::query()
            ->where('social_account_id', $item->social_account_id)
            ->where('provider', $item->provider)
            ->where('thread_id', $item->thread_id)
            ->whereKeyNot($item->id)
            ->where(fn ($query) => $query->whereNull('reply_window_ends_at')->orWhere('reply_window_ends_at', '<', $item->reply_window_ends_at))
            ->update(['reply_window_ends_at' => $item->reply_window_ends_at]);

        $latest = InboxItem::query()
            ->where('social_account_id', $item->social_account_id)
            ->where('provider', $item->provider)
            ->where('thread_id', $item->thread_id)
            ->max('reply_window_ends_at');

        if ($latest !== null && CarbonImmutable::parse($latest)->greaterThan($item->reply_window_ends_at)) {
            $item->forceFill(['reply_window_ends_at' => CarbonImmutable::parse($latest)])->save();
        }
    }

    /**
     * Keeps what can change on the network (hidden, can_reply) current.
     */
    private function refresh(InboxItem $item, InboxItemData $data): void
    {
        $item->fill([
            'can_reply' => $data->canReply,
            'hidden_at' => $data->hidden ? ($item->hidden_at ?? now()) : null,
        ]);

        if ($item->isDirty()) {
            $item->save();
        }
    }

    private function isOurReply(SocialAccount $account, string $externalId): bool
    {
        return InboxReply::query()
            ->where('social_account_id', $account->id)
            ->where('external_id', $externalId)
            ->exists();
    }

    private function postId(SocialAccount $account, ?string $postExternalId): ?string
    {
        if ($postExternalId === null || $postExternalId === '') {
            return null;
        }

        return Post::query()
            ->where('social_account_id', $account->id)
            ->where('platform_post_id', $postExternalId)
            ->value('id');
    }
}
