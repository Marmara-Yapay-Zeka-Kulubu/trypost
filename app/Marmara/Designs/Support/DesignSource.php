<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Support;

/**
 * How a design got into the shared folder, shown as a small badge next to
 * "Ekleyen": the page's "Yükle" (site), Hermes for a WhatsApp sender
 * (whatsapp), Hermes / an MCP client on its own (agent), or a file dropped
 * straight into Drive (drive). The person is `uploader_user_id`, so the
 * uploader filter groups one person's files whatever their source.
 */
enum DesignSource: string
{
    case Site = 'site';
    case WhatsApp = 'whatsapp';
    case Drive = 'drive';
    case Agent = 'agent';

    public const DEFAULT = self::Drive;

    public function label(?string $locale = null): string
    {
        return (string) __("marmara-designs::designs.sources.{$this->value}", [], $locale);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
