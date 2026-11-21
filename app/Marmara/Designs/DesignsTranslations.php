<?php

declare(strict_types=1);

namespace App\Marmara\Designs;

/**
 * The gallery's copy. Like the inbox, the module keeps its own lang files
 * (app/Marmara/Designs/lang/{en,tr}, namespace `marmara-designs`) and hands
 * the page the current locale's strings (English for a locale it does not
 * ship) as a prop.
 */
class DesignsTranslations
{
    /**
     * @return array<string, mixed>
     */
    public static function forLocale(string $locale): array
    {
        $english = (array) trans('marmara-designs::designs', [], 'en');
        $local = $locale === 'en' ? [] : trans('marmara-designs::designs', [], $locale);

        return array_replace_recursive($english, is_array($local) ? $local : []);
    }
}
