<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Analytics;

use App\Enums\SocialAccount\Platform;
use App\Marmara\Hooks\AnalyticsPlatformGate;
use App\Marmara\LinkedIn\LinkedInConfig;

/**
 * LinkedIn pages are in analytics while the native module is on, or while
 * the wrapped gate (Zernio's) includes them. Like Zernio's gate this is per
 * platform: a LinkedIn page that neither measures shows without data (its
 * collectors answer unsupported).
 */
class LinkedInAnalyticsPlatformGate extends AnalyticsPlatformGate
{
    public function __construct(
        private readonly AnalyticsPlatformGate $previous,
        private readonly LinkedInConfig $config,
    ) {}

    public function includes(Platform $platform): bool
    {
        return ($platform === Platform::LinkedInPage && $this->config->enabled())
            || $this->previous->includes($platform);
    }
}
