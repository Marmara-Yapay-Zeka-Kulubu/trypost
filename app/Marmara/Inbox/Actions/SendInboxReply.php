<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Actions;

use App\Marmara\Inbox\Exceptions\InboxProviderException;
use App\Marmara\Inbox\Exceptions\InboxReplyRefused;
use App\Marmara\Inbox\InboxProviders;
use App\Marmara\Inbox\InboxReplyEvents;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Models\InboxReply;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sends a reply to an inbox item, once per `request_id`: the same request
 * id answers the stored reply (sent, failed or still sending) without
 * calling the network again. One flow for the page, the API and MCP.
 *
 * One reply per item unless the caller saw the others: under a lock on the
 * item row, a `sending` reply refuses the send (`reply_in_progress`) and a
 * `sent` one too (`already_replied`) unless `$acknowledgedReplyId` is that
 * reply or a newer one (a reply newer than the acknowledged one refuses
 * again). The `sending` row is written inside the lock and the network call
 * runs after it, so the row itself keeps concurrent senders out. A `sending`
 * row older than STALE_SENDING_MINUTES (the process died mid-call) no longer
 * means "in progress"; its outcome is unknown, so it counts as a reply that
 * needs an acknowledgement, and it stays `sending` in the history.
 *
 * `$via` says how the reply was made (InboxReply::VIA_*). `$onBehalfOf` is
 * the member an agent replies for (MCP `on_behalf_of_user_id`): the reply is
 * credited to them (`user_id`) and `$user`, whose token sent it, is kept as
 * `agent_user_id`. Permissions are the caller's business and follow `$user`.
 * The move from `sending` to `sent` is one conditional update, and only the
 * call that made it fires `inbox.reply_sent` (InboxReplyEvents), so a reply
 * is announced once whatever retries or replays follow.
 */
final class SendInboxReply
{
    private const string WINDOW_CLOSED = 'reply_window_closed';

    public const int STALE_SENDING_MINUTES = 5;

    /**
     * @return array{reply: InboxReply, replayed: bool}
     *
     * @throws InboxReplyRefused
     */
    public static function execute(
        InboxItem $item,
        ?User $user,
        string $text,
        ?string $requestId = null,
        ?string $acknowledgedReplyId = null,
        ?string $via = null,
        ?User $onBehalfOf = null,
    ): array {
        $requestId ??= (string) Str::uuid();
        $text = trim($text);

        $previous = self::previous($item, $requestId);

        if ($previous !== null) {
            return self::replay($previous);
        }

        $account = $item->socialAccount;
        $provider = $account !== null ? app(InboxProviders::class)->for($account) : null;

        if ($account === null || $provider === null || $provider->key() !== $item->provider) {
            throw InboxReplyRefused::cannotReply('This channel is no longer read by the inbox.');
        }

        if (! (bool) data_get($provider->capabilities($account), "{$item->kind}.reply", false)) {
            throw InboxReplyRefused::cannotReply("Replies to {$item->kind}s are not possible on {$item->platform}.");
        }

        $missing = $provider->missingReplyPermission($account, $item->kind);

        if ($missing !== null) {
            throw InboxReplyRefused::cannotReply("The channel needs permission {$missing} to reply.");
        }

        if (! $item->can_reply) {
            throw InboxReplyRefused::cannotReply('The network does not allow a reply to this item.');
        }

        if ($item->replyWindowClosed()) {
            throw InboxReplyRefused::windowClosed();
        }

        $problem = $provider->replyTextProblem($item, $text);

        if ($problem !== null) {
            throw InboxReplyRefused::tooLong($problem);
        }

        try {
            $claimed = DB::transaction(function () use ($item, $user, $text, $requestId, $acknowledgedReplyId, $via, $onBehalfOf): array {
                InboxItem::query()->whereKey($item->id)->lockForUpdate()->first();

                $previous = self::previous($item, $requestId);

                if ($previous !== null) {
                    return ['reply' => $previous, 'replayed' => true];
                }

                self::ensureNoOtherReply($item, $acknowledgedReplyId);

                return ['reply' => InboxReply::query()->create([
                    'inbox_item_id' => $item->id,
                    'workspace_id' => $item->workspace_id,
                    'social_account_id' => $item->social_account_id,
                    'user_id' => ($onBehalfOf ?? $user)?->id,
                    'agent_user_id' => $onBehalfOf !== null ? $user?->id : null,
                    'via' => $via,
                    'request_id' => $requestId,
                    'text' => $text,
                    'status' => InboxReply::STATUS_SENDING,
                ]), 'replayed' => false];
            });
        } catch (UniqueConstraintViolationException) {
            return self::replay(self::previous($item, $requestId) ?? throw InboxReplyRefused::cannotReply('The reply is being sent.'));
        }

        if ($claimed['replayed']) {
            return self::replay($claimed['reply']);
        }

        $reply = $claimed['reply'];

        try {
            $externalId = $provider->reply($item, $text, $reply->id);
            $sent = [
                'status' => InboxReply::STATUS_SENT,
                'external_id' => $externalId !== '' ? $externalId : null,
                'sent_at' => now(),
            ];
            // Only from `sending`: the one update that makes it sent announces it.
            $moved = InboxReply::query()
                ->whereKey($reply->id)
                ->where('status', InboxReply::STATUS_SENDING)
                ->update($sent);
            $reply->forceFill($sent)->syncOriginal();
            $item->forceFill(['read_at' => $item->read_at ?? now()])->save();

            if ($moved === 1) {
                app(InboxReplyEvents::class)->sent($reply);
            }
        } catch (InboxProviderException $exception) {
            $windowClosed = $exception->category === 'window_closed';
            $reply->forceFill([
                'status' => InboxReply::STATUS_FAILED,
                'error' => mb_substr(($windowClosed ? self::WINDOW_CLOSED.': ' : '').$exception->getMessage(), 0, 1000),
            ])->save();

            if ($windowClosed) {
                $item->forceFill(['reply_window_ends_at' => now()->subSecond()])->save();

                throw InboxReplyRefused::windowClosed();
            }
        }

        return ['reply' => $reply, 'replayed' => false];
    }

    /**
     * The stored answer of a request id: the reply, or the same 409 when the
     * network refused it as outside the messaging window.
     *
     * @return array{reply: InboxReply, replayed: bool}
     */
    private static function replay(InboxReply $reply): array
    {
        if ($reply->status === InboxReply::STATUS_FAILED && str_starts_with((string) $reply->error, self::WINDOW_CLOSED)) {
            throw InboxReplyRefused::windowClosed();
        }

        return ['reply' => $reply, 'replayed' => true];
    }

    /**
     * Refuses when another reply is on its way (`reply_in_progress`) or was
     * sent (`already_replied`) and the caller did not acknowledge it or a
     * newer one. Runs under the item's row lock.
     *
     * @throws InboxReplyRefused
     */
    private static function ensureNoOtherReply(InboxItem $item, ?string $acknowledgedReplyId): void
    {
        /** @var Collection<int, InboxReply> $others */
        $others = InboxReply::query()
            ->with('user')
            ->where('inbox_item_id', $item->id)
            ->whereIn('status', [InboxReply::STATUS_SENDING, InboxReply::STATUS_SENT])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $inProgress = $others->first(fn (InboxReply $reply): bool => $reply->status === InboxReply::STATUS_SENDING && ! self::stale($reply));

        if ($inProgress !== null) {
            throw InboxReplyRefused::inProgress($inProgress);
        }

        $newest = $others->first();

        if ($newest === null) {
            return;
        }

        $acknowledged = $acknowledgedReplyId !== null && Str::isUuid($acknowledgedReplyId)
            ? InboxReply::query()->where('inbox_item_id', $item->id)->whereKey($acknowledgedReplyId)->first()
            : null;

        if ($acknowledged !== null && ($newest->is($acknowledged) || ! self::newer($newest, $acknowledged))) {
            return;
        }

        throw InboxReplyRefused::alreadyReplied($newest);
    }

    private static function stale(InboxReply $reply): bool
    {
        return $reply->created_at !== null && $reply->created_at->lt(now()->subMinutes(self::STALE_SENDING_MINUTES));
    }

    /**
     * Whether `$reply` came after `$than`: by creation time, then by the
     * time-ordered (v7) uuid within the same second.
     */
    private static function newer(InboxReply $reply, InboxReply $than): bool
    {
        $a = $reply->created_at?->getTimestamp() ?? 0;
        $b = $than->created_at?->getTimestamp() ?? 0;

        return $a !== $b ? $a > $b : strcmp($reply->id, $than->id) > 0;
    }

    private static function previous(InboxItem $item, string $requestId): ?InboxReply
    {
        return InboxReply::query()
            ->where('inbox_item_id', $item->id)
            ->where('request_id', $requestId)
            ->first();
    }
}
