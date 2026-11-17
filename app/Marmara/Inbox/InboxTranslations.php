<?php

declare(strict_types=1);

namespace App\Marmara\Inbox;

/**
 * The inbox page's copy. The module keeps its own lang files
 * (app/Marmara/Inbox/lang/{en,tr}, namespace `marmara-inbox`) instead of
 * TryPost's 16 locale files, and hands the page the current locale's strings
 * (English for a locale the module does not ship) as a prop.
 */
class InboxTranslations
{
    /**
     * @return array<string, mixed>
     */
    public static function forLocale(string $locale): array
    {
        $english = (array) trans('marmara-inbox::inbox', [], 'en');
        $local = $locale === 'en' ? [] : trans('marmara-inbox::inbox', [], $locale);

        return array_replace_recursive($english, is_array($local) ? $local : []);
    }
}
