<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Support;

/**
 * Where a design stands in the club's workflow. The labels (Taslak,
 * Değerlendirmede, Onaylandı, Kullanıldı, Arşiv) are in the module's lang
 * files under `statuses`.
 */
enum DesignStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Used = 'used';
    case Archived = 'archived';

    /**
     * Setting these needs the approve / publish right (DesignPolicy::setStatus).
     */
    public function needsApprover(): bool
    {
        return in_array($this, [self::Approved, self::Used], true);
    }

    public function label(?string $locale = null): string
    {
        return (string) __("marmara-designs::designs.statuses.{$this->value}", [], $locale);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
