<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Mentions;

/**
 * The answer of one vanity name lookup: the organization, or why there is
 * none (`status` is LinkedIn's HTTP status when it answered).
 */
final readonly class OrganizationLookup
{
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
        public ?int $status = null,
        public string $reason = '',
    ) {}

    public static function found(string $id, ?string $name, int $status = 200): self
    {
        return new self(id: $id, name: $name, status: $status);
    }

    public static function failed(string $reason, ?int $status = null): self
    {
        return new self(status: $status, reason: $reason);
    }

    public function resolved(): bool
    {
        return $this->id !== null;
    }

    public function urn(): ?string
    {
        return $this->id === null ? null : "urn:li:organization:{$this->id}";
    }
}
