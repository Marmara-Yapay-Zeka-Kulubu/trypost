<?php

declare(strict_types=1);

namespace App\Marmara\Hooks;

/**
 * What SocialAccountPublishGuard decided about a post's channel before the
 * publish job runs its publisher.
 */
final class PublishGuardVerdict
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    private function __construct(
        public readonly bool $checksNativeConnection,
        public readonly ?string $failureMessage = null,
        public readonly ?array $context = null,
    ) {}

    /**
     * Upstream behaviour: the job checks the native connection (status, scopes).
     */
    public static function native(): self
    {
        return new self(checksNativeConnection: true);
    }

    /**
     * The channel publishes through another service that found it healthy:
     * the native connection is not checked.
     */
    public static function passed(): self
    {
        return new self(checksNativeConnection: false);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function failed(string $message, array $context = []): self
    {
        return new self(checksNativeConnection: false, failureMessage: $message, context: $context);
    }

    public function hasFailed(): bool
    {
        return $this->failureMessage !== null;
    }
}
