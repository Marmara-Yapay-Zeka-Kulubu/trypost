<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Analytics;

use App\Enums\SocialAccount\Platform;
use App\Marmara\Hooks\AnalyticsPlatformGate;
use App\Marmara\Zernio\ZernioConfig;

/**
 * LinkedIn pages are in TryPost's analytics while at least one is mapped to
 * Zernio (with analytics on). TryPost decides this per platform, not per
 * channel: an unmapped LinkedIn page in the same install then shows in
 * analytics without data (its collectors refuse it as unsupported).
 */
class ZernioAnalyticsPlatformGate extends AnalyticsPlatformGate
{
    public function __construct(private readonly ZernioConfig $config) {}

    public function includes(Platform $platform): bool
    {
        return $platform === Platform::LinkedInPage
            ? $this->config->analyticsEnabled() && $this->config->hasAccounts($platform)
            : parent::includes($platform);
    }
}
