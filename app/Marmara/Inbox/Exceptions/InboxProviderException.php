<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Exceptions;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A provider's refusal, as a category the sync and the reply flow act on:
 * needs_permission (the detail names it), rate_limited (until retryAt),
 * authentication, window_closed, unsupported, transient, error.
 * Messages never carry tokens or request data.
 */
class InboxProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        string $message,
        public readonly ?CarbonImmutable $retryAt = null,
    ) {
        parent::__construct($message);
    }

    public static function needsPermission(string $permission): self
    {
        return new self('needs_permission', "needs permission {$permission}");
    }

    public static function rateLimited(string $message, ?CarbonImmutable $retryAt): self
    {
        return new self('rate_limited', $message, $retryAt);
    }

    public static function unsupported(string $message): self
    {
        return new self('unsupported', $message);
    }

    public static function windowClosed(string $message = 'the messaging window has closed'): self
    {
        return new self('window_closed', $message);
    }
}
