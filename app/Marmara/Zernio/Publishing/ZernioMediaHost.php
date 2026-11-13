<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Publishing;

use App\Dto\MediaItem;
use App\Exceptions\PlatformUnavailableException;
use App\Marmara\Zernio\Exceptions\ZernioPublishException;
use App\Marmara\Zernio\ZernioClient;
use App\Marmara\Zernio\ZernioConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The URL Zernio fetches a media file from: TryPost's own public URL
 * (`ZERNIO_MEDIA_MODE=url`), or a copy uploaded to Zernio's storage through
 * a presigned URL (`upload`, the default).
 *
 * @see https://docs.zernio.com/guides/media-uploads
 */
class ZernioMediaHost
{
    public function __construct(
        private readonly ZernioConfig $config,
        private readonly ZernioClient $client,
    ) {}

    public function url(MediaItem $item, string $fallbackMime, string $platform = 'tiktok'): string
    {
        if ($this->config->mediaMode() === ZernioConfig::MEDIA_MODE_URL) {
            return $item->url;
        }

        $contentType = filled($item->mime_type) ? (string) $item->mime_type : $fallbackMime;
        $filename = filled($item->original_filename) ? (string) $item->original_filename : basename($item->path);
        $tempFile = tempnam(sys_get_temp_dir(), 'zernio_media_');

        try {
            try {
                $download = Http::sink($tempFile)->timeout($this->config->timeout() * 5)->get($item->url);
            } catch (ConnectionException $e) {
                throw self::unavailable('Could not download the media for Zernio: '.$e->getMessage());
            }

            if ($download->failed()) {
                throw self::unavailable("Could not download the media for Zernio (HTTP {$download->status()}).");
            }

            $size = filesize($tempFile) ?: null;

            try {
                $presign = $this->client->presignMedia($filename, $contentType, $size);
            } catch (ConnectionException $e) {
                throw self::unavailable('Zernio did not answer the upload request: '.$e->getMessage());
            }

            if ($presign->failed()) {
                if ($presign->serverError() || $presign->status() === 429) {
                    throw self::unavailable("Zernio refused the upload URL (HTTP {$presign->status()}).", $presign);
                }

                throw ZernioPublishException::fromApiResponse($presign, $platform);
            }

            $uploadUrl = data_get($presign->json(), 'uploadUrl');
            $publicUrl = data_get($presign->json(), 'publicUrl');

            if (! is_string($uploadUrl) || ! is_string($publicUrl) || $uploadUrl === '' || $publicUrl === '') {
                throw ZernioPublishException::misconfigured('Zernio did not return an upload URL.', $platform);
            }

            try {
                $upload = $this->client->uploadToPresignedUrl($uploadUrl, $tempFile, $contentType);
            } catch (ConnectionException $e) {
                throw self::unavailable('Uploading the media to Zernio failed: '.$e->getMessage());
            }

            if ($upload->failed()) {
                throw self::unavailable("Uploading the media to Zernio failed (HTTP {$upload->status()}).");
            }

            return $publicUrl;
        } finally {
            @unlink($tempFile);
        }
    }

    public static function unavailable(string $message, ?Response $response = null): PlatformUnavailableException
    {
        $retryAfter = (int) ($response?->header('Retry-After') ?? 0);

        return new PlatformUnavailableException(
            message: $message,
            httpStatus: $response?->status(),
            context: ['zernio_detail' => $message],
            retryDelaySeconds: $retryAfter > 0 ? $retryAfter : null,
        );
    }
}
