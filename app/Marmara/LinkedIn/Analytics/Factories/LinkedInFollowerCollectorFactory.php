<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Analytics\Factories;

use App\Enums\SocialAccount\Platform;
use App\Marmara\LinkedIn\Analytics\Collectors\NativeLinkedInPageFollowerCollector;
use App\Marmara\LinkedIn\LinkedInConfig;
use App\Services\Analytics\Collectors\Followers\AbstractFollowerCollector;
use App\Services\Analytics\Collectors\Followers\FollowerCollectorFactory;

/**
 * Wraps the previous factory; the LinkedIn page collector decides per channel.
 */
class LinkedInFollowerCollectorFactory extends FollowerCollectorFactory
{
    public function __construct(
        private readonly FollowerCollectorFactory $previous,
        private readonly LinkedInConfig $config,
    ) {}

    public function supports(Platform $platform): bool
    {
        return $this->previous->supports($platform) || ($platform === Platform::LinkedInPage && $this->config->enabled());
    }

    public function for(Platform $platform): AbstractFollowerCollector
    {
        if ($platform !== Platform::LinkedInPage || ! $this->config->enabled()) {
            return $this->previous->for($platform);
        }

        return app(NativeLinkedInPageFollowerCollector::class)
            ->withFallback(fn (): AbstractFollowerCollector => $this->previous->for($platform));
    }
}
