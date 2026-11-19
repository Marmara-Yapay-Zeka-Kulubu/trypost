<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Exceptions;

use App\Marmara\Inbox\Models\InboxReply;
use RuntimeException;

/**
 * A reply refused before anything was sent: the window has closed (409),
 * someone already replied (409 `already_replied`) or is replying right now
 * (409 `reply_in_progress`), the text is too long for the network (422), or
 * the network does not let this channel reply (422). `reason` is the
 * machine-readable code; `existingReply` is the reply that blocked a send.
 */
class InboxReplyRefused extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status,
        public readonly ?InboxReply $existingReply = null,
    ) {
        parent::__construct($message);
    }

    public static function windowClosed(): self
    {
        return new self('reply_window_closed', 'The reply window for this conversation has closed.', 409);
    }

    public static function alreadyReplied(InboxReply $reply): self
    {
        return new self('already_replied', 'Someone already replied to this item. Send acknowledged_reply_id to reply anyway.', 409, $reply);
    }

    public static function inProgress(InboxReply $reply): self
    {
        return new self('reply_in_progress', 'Someone is sending a reply to this item right now. Try again in a few seconds.', 409, $reply);
    }

    public static function tooLong(string $message): self
    {
        return new self('text_too_long', $message, 422);
    }

    public static function cannotReply(string $message): self
    {
        return new self('cannot_reply', $message, 422);
    }
}
