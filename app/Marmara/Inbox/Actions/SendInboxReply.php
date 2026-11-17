<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Actions;

use App\Marmara\Inbox\Exceptions\InboxProviderException;
use App\Marmara\Inbox\Exceptions\InboxReplyRefused;
use App\Marmara\Inbox\InboxProviders;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Models\InboxReply;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Sends a reply to an inbox item, once per `request_id`: the same request
 * id answers the stored reply (sent, failed or still sending) without
 * calling the network again. One flow for the page, the API and MCP.
 */
final class SendInboxReply
{
    private const string WINDOW_CLOSED = 'reply_window_closed';

    /**
     * @return array{reply: InboxReply, replayed: bool}
     *
     * @throws InboxReplyRefused
     */
    public static function execute(InboxItem $item, ?User $user, string $text, ?string $requestId = null): array
    {
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
            $reply = InboxReply::query()->create([
                'inbox_item_id' => $item->id,
                'workspace_id' => $item->workspace_id,
                'social_account_id' => $item->social_account_id,
                'user_id' => $user?->id,
                'request_id' => $requestId,
                'text' => $text,
                'status' => InboxReply::STATUS_SENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            return self::replay(self::previous($item, $requestId) ?? throw InboxReplyRefused::cannotReply('The reply is being sent.'));
        }

        try {
            $externalId = $provider->reply($item, $text, $reply->id);
            $reply->forceFill([
                'status' => InboxReply::STATUS_SENT,
                'external_id' => $externalId !== '' ? $externalId : null,
                'sent_at' => now(),
            ])->save();
            $item->forceFill(['read_at' => $item->read_at ?? now()])->save();
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

    private static function previous(InboxItem $item, string $requestId): ?InboxReply
    {
        return InboxReply::query()
            ->where('inbox_item_id', $item->id)
            ->where('request_id', $requestId)
            ->first();
    }
}
