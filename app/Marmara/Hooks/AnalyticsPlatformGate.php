<?php

declare(strict_types=1);

namespace App\Marmara\Hooks;

use App\Enums\SocialAccount\Platform;

/**
 * Marmara AI fork extension point in Platform::isIncludedInAnalytics(), for
 * the platforms upstream leaves out of analytics (LinkedIn pages).
 *
 * This default keeps upstream behaviour: such a platform stays out. A module
 * that measures one through another service rebinds this class in the
 * container (see app/Marmara/README.md).
 */
class AnalyticsPlatformGate
{
    public function includes(Platform $platform): bool
    {
        return false;
    }
}
