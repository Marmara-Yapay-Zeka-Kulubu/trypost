<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Analytics\Factories;

use App\Enums\SocialAccount\Platform;
use App\Marmara\Zernio\Analytics\Collectors\ZernioLinkedInPageFollowerCollector;
use App\Marmara\Zernio\ZernioConfig;
use App\Services\Analytics\Collectors\Followers\AbstractFollowerCollector;
use App\Services\Analytics\Collectors\Followers\FollowerCollectorFactory;

/**
 * Adds LinkedIn page followers (read from Zernio) to TryPost's factory.
 */
class ZernioFollowerCollectorFactory extends FollowerCollectorFactory
{
    public function __construct(private readonly ZernioConfig $config) {}

    public function supports(Platform $platform): bool
    {
        return parent::supports($platform) || $this->zernioMeasures($platform);
    }

    public function for(Platform $platform): AbstractFollowerCollector
    {
        return $this->zernioMeasures($platform)
            ? app(ZernioLinkedInPageFollowerCollector::class)
            : parent::for($platform);
    }

    private function zernioMeasures(Platform $platform): bool
    {
        return $platform === Platform::LinkedInPage && $this->config->analyticsEnabled() && $this->config->hasAccounts($platform);
    }
}
