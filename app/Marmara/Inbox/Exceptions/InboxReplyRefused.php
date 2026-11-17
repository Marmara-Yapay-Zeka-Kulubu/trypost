<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Exceptions;

use RuntimeException;

/**
 * A reply refused before anything was sent: the window has closed (409),
 * the text is too long for the network (422), or the network does not let
 * this channel reply (422). `reason` is the machine-readable code.
 */
class InboxReplyRefused extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function windowClosed(): self
    {
        return new self('reply_window_closed', 'The reply window for this conversation has closed.', 409);
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
