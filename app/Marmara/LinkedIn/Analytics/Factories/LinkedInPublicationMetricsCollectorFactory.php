<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Analytics\Factories;

use App\Enums\SocialAccount\Platform;
use App\Marmara\LinkedIn\Analytics\Collectors\NativeLinkedInPagePublicationMetricsCollector;
use App\Marmara\LinkedIn\LinkedInConfig;
use App\Services\Analytics\Collectors\Metrics\AbstractPublicationMetricsCollector;
use App\Services\Analytics\Collectors\Metrics\PublicationMetricsCollectorFactory;

/**
 * Wraps the previous factory. The factory is asked per platform, so the
 * LinkedIn page collector decides per channel and hands a channel it does not
 * measure to the wrapped factory's collector.
 */
class LinkedInPublicationMetricsCollectorFactory extends PublicationMetricsCollectorFactory
{
    public function __construct(
        private readonly PublicationMetricsCollectorFactory $previous,
        private readonly LinkedInConfig $config,
    ) {}

    public function for(Platform $platform): AbstractPublicationMetricsCollector
    {
        if ($platform !== Platform::LinkedInPage || ! $this->config->enabled()) {
            return $this->previous->for($platform);
        }

        return app(NativeLinkedInPagePublicationMetricsCollector::class)
            ->withFallback(fn (): AbstractPublicationMetricsCollector => $this->previous->for($platform));
    }
}
