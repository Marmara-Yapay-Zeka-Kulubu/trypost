<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Analytics\Factories;

use App\Enums\SocialAccount\Platform;
use App\Marmara\Zernio\Analytics\Collectors\ZernioLinkedInPagePublicationMetricsCollector;
use App\Marmara\Zernio\ZernioConfig;
use App\Services\Analytics\Collectors\Metrics\AbstractPublicationMetricsCollector;
use App\Services\Analytics\Collectors\Metrics\PublicationMetricsCollectorFactory;

/**
 * Adds LinkedIn page post metrics (read from Zernio) to TryPost's factory.
 */
class ZernioPublicationMetricsCollectorFactory extends PublicationMetricsCollectorFactory
{
    public function __construct(private readonly ZernioConfig $config) {}

    public function for(Platform $platform): AbstractPublicationMetricsCollector
    {
        return $platform === Platform::LinkedInPage && $this->config->analyticsEnabled() && $this->config->hasAccounts($platform)
            ? app(ZernioLinkedInPagePublicationMetricsCollector::class)
            : parent::for($platform);
    }
}
