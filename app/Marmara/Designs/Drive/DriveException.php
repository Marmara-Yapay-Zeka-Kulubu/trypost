<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Drive;

use RuntimeException;

/**
 * A Drive (or Google OAuth) answer the module cannot use. `status` is the
 * HTTP status (0 for a configuration problem or no answer), `reason` Google's
 * error reason when it gave one (e.g. storageQuotaExceeded, notFound).
 */
class DriveException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly ?string $reason = null)
    {
        parent::__construct($message, $status);
    }

    public static function configuration(string $message): self
    {
        return new self($message);
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429
            || ($this->status === 403 && in_array($this->reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'dailyLimitExceeded'], true));
    }
}
