<?php

declare(strict_types=1);

namespace App\Marmara\Portal;

use App\Enums\User\Locale;
use Illuminate\Support\Str;

/**
 * Reads config/marmara-portal.php (and APP_LOCALE) for the club portal module.
 * Everything is read per call, so a test or a config change applies at once.
 */
class PortalConfig
{
    private const LOGO_FILES = ['svg', 'png', 'webp'];

    /**
     * The language guests and language-less sign-ups get, or null when it is
     * TryPost's own default (then nothing here changes upstream behaviour).
     */
    public function defaultLocale(): ?Locale
    {
        $locale = Locale::tryFrom((string) config('app.locale'));

        return $locale === null || $locale === Locale::DEFAULT ? null : $locale;
    }

    public function brandingEnabled(): bool
    {
        return (bool) config('marmara-portal.branding.enabled', false);
    }

    /**
     * What the guest layout needs, or null with branding off.
     *
     * @return array{name: string, tagline: string|null, logo: string|null, logoDark: string|null, accent: string|null, sourceUrl: string|null}|null
     */
    public function branding(): ?array
    {
        if (! $this->brandingEnabled()) {
            return null;
        }

        $logo = $this->asset(config('marmara-portal.branding.logo'), 'logo');

        return [
            'name' => (string) (config('marmara-portal.branding.name') ?: 'Marmara Yapay Zeka Kulübü'),
            'tagline' => filled(config('marmara-portal.branding.tagline')) ? (string) config('marmara-portal.branding.tagline') : null,
            'logo' => $logo,
            'logoDark' => $logo === null ? null : $this->asset(config('marmara-portal.branding.logo_dark'), 'logo-dark'),
            'accent' => $this->accent(),
            'sourceUrl' => $this->url(config('marmara-portal.branding.source_url')),
        ];
    }

    private function accent(): ?string
    {
        $accent = trim((string) config('marmara-portal.branding.accent'));

        return preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $accent) === 1 ? Str::lower($accent) : null;
    }

    /**
     * A configured URL or public/ path, else the first public/marmara/{name}.*
     * file that exists.
     */
    private function asset(mixed $configured, string $name): ?string
    {
        $configured = trim((string) $configured);

        if ($configured !== '') {
            if ($url = $this->url($configured)) {
                return $url;
            }

            $path = ltrim($configured, '/');

            return is_file(public_path($path)) ? asset($path) : null;
        }

        foreach (self::LOGO_FILES as $extension) {
            $path = "marmara/{$name}.{$extension}";

            if (is_file(public_path($path))) {
                return asset($path);
            }
        }

        return null;
    }

    private function url(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('#^https?://#i', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null;
    }
}
