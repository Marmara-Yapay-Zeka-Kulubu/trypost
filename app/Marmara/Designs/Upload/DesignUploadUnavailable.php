<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Upload;

use RuntimeException;

/**
 * Uploading is not possible, with a Turkish (or the user's locale) message
 * that can be shown as is on the page, in the API answer or by Echo.
 * `reason`: not_connected, folder_not_granted, reconnect, unsupported,
 * too_large, quota, failed.
 */
class DesignUploadUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, ?string $message = null)
    {
        parent::__construct($message ?? (string) __("marmara-designs::designs.upload.{$reason}"));
    }
}
