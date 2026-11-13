<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Analytics\Factories;

use App\Enums\SocialAccount\Platform;
use App\Marmara\Zernio\Analytics\Collectors\ZernioLinkedInPagePublicationCollector;
use App\Marmara\Zernio\ZernioConfig;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\AbstractPublicationHistoryCollector;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;

/**
 * Adds LinkedIn pages (read from Zernio) to TryPost's factory, which has no
 * LinkedIn collector; every other platform is the parent's.
 */
class ZernioPublicationHistoryCollectorFactory extends PublicationHistoryCollectorFactory
{
    public function __construct(private readonly ZernioConfig $config) {}

    public function supports(Platform $platform): bool
    {
        return parent::supports($platform) || $this->zernioMeasures($platform);
    }

    public function for(SocialAccount $account): AbstractPublicationHistoryCollector
    {
        return $this->zernioMeasures($account->platform)
            ? app(ZernioLinkedInPagePublicationCollector::class)
            : parent::for($account);
    }

    private function zernioMeasures(Platform $platform): bool
    {
        return $platform === Platform::LinkedInPage && $this->config->analyticsEnabled() && $this->config->hasAccounts($platform);
    }
}
