<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Support;

/**
 * Which Drive files are designs, and of which kind. Everything else in the
 * folder (documents, spreadsheets, archives…) is skipped by the sync.
 */
final class DesignTypes
{
    public const string IMAGE = 'image';

    public const string PDF = 'pdf';

    public const string VIDEO = 'video';

    public const string DRAWING = 'drawing';

    public const array KINDS = [self::IMAGE, self::PDF, self::VIDEO, self::DRAWING];

    public const array IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public const string DRAWING_MIME = 'application/vnd.google-apps.drawing';

    /** What a Google Drawing is exported as (preview and post draft). */
    public const string DRAWING_EXPORT_MIME = 'image/png';

    public static function kindFor(?string $mimeType): ?string
    {
        $mimeType = strtolower((string) $mimeType);

        return match (true) {
            in_array($mimeType, self::IMAGE_MIMES, true) => self::IMAGE,
            $mimeType === 'application/pdf' => self::PDF,
            str_starts_with($mimeType, 'video/') => self::VIDEO,
            $mimeType === self::DRAWING_MIME => self::DRAWING,
            default => null,
        };
    }

    public static function supported(?string $mimeType): bool
    {
        return self::kindFor($mimeType) !== null;
    }

    /**
     * Google Workspace files have no binary content: no md5, no
     * keepForever, exported instead of downloaded.
     */
    public static function isGoogleNative(?string $mimeType): bool
    {
        return str_starts_with((string) $mimeType, 'application/vnd.google-apps.');
    }

    public static function extensionFor(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'image/jpeg' => 'jpg',
            'image/png', self::DRAWING_MIME => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'application/pdf' => 'pdf',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            default => str_starts_with($mimeType, 'video/') ? 'mp4' : 'bin',
        };
    }
}
