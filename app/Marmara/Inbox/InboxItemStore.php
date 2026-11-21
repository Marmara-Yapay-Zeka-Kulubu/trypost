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
 * Stores what a provider read: one row per (channel, external id), whichever
 * provider read it, so a comment read twice is stored once and
 * `inbox.item_received` fires once, after the row commits. The channel's own comments and messages
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
        $ids = array_values(array_unique([$data->externalId, ...$data->aliases]));

        if ($data->fromSelf || $this->isOurReply($account, $ids)) {
            return null;
        }

        // Any provider: the ids are the network's own (Zernio's list id is
        // Meta's `mid` too), so a channel that moved from the native provider
        // to Zernio (or back) does not get its messages a second time.
        $known = InboxItem::query()
            ->where('social_account_id', $account->id)
            ->whereIn('external_id', $ids)
            ->get();

        if ($known->isNotEmpty()) {
            $canonical = $known->first(fn (InboxItem $item): bool => $item->provider === $provider->key() && $item->external_id === $data->externalId)
                ?? $known->firstWhere('external_id', $data->externalId)
                ?? $known->firstWhere('provider', $provider->key())
                ?? $known->first();

            // A row stored under an alias only (an older id): move it to the
            // canonical id, so the other path finds it from now on.
            if ($canonical->external_id !== $data->externalId && $canonical->provider === $provider->key()) {
                $canonical->external_id = $data->externalId;
            }

            $this->refresh($canonical, $data);

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
     * Keeps what can change on the network (hidden, can_reply) current, and
     * fills the text of an item stored without it (polled while Zernio kept
     * no content, then delivered by the webhook with it).
     */
    private function refresh(InboxItem $item, InboxItemData $data): void
    {
        $storedWithoutContent = self::blank($item->text) || (bool) data_get($item->meta, 'content_not_retained', false);
        $hasContent = ! self::blank($data->text) && ! (bool) ($data->meta['content_not_retained'] ?? false);

        if ($storedWithoutContent && $hasContent) {
            $item->text = $data->text;
            $item->meta = [...($item->meta ?? []), 'content_not_retained' => false];
        }

        $item->fill([
            'meta' => $data->meta === [] ? $item->meta : [...($item->meta ?? []), ...$data->meta],
            'can_reply' => $data->canReply,
            'hidden_at' => $data->hidden ? ($item->hidden_at ?? now()) : null,
        ]);

        if ($item->isDirty()) {
            $item->save();
        }
    }

    /**
     * Null, '' and whitespace only: rows stored before the renderer existed
     * hold '' for a story reply with no text.
     */
    private static function blank(?string $text): bool
    {
        return $text === null || trim($text) === '';
    }

    /**
     * @param  list<string>  $externalIds
     */
    private function isOurReply(SocialAccount $account, array $externalIds): bool
    {
        return InboxReply::query()
            ->where('social_account_id', $account->id)
            ->whereIn('external_id', $externalIds)
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
