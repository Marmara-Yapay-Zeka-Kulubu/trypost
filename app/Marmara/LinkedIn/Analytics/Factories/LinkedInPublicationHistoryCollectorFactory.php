<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Analytics\Factories;

use App\Enums\SocialAccount\Platform;
use App\Marmara\LinkedIn\Analytics\Collectors\NativeLinkedInPagePublicationCollector;
use App\Marmara\LinkedIn\LinkedInConfig;
use App\Marmara\LinkedIn\NativeLinkedInAccounts;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\AbstractPublicationHistoryCollector;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;

/**
 * Wraps the factory the container would hand out without this module
 * (upstream's, or Zernio's subclass): a natively measured LinkedIn page gets
 * the native collector, every other channel the wrapped factory's.
 */
class LinkedInPublicationHistoryCollectorFactory extends PublicationHistoryCollectorFactory
{
    public function __construct(
        private readonly PublicationHistoryCollectorFactory $previous,
        private readonly LinkedInConfig $config,
        private readonly NativeLinkedInAccounts $accounts,
    ) {}

    public function supports(Platform $platform): bool
    {
        return $this->previous->supports($platform) || ($platform === Platform::LinkedInPage && $this->config->enabled());
    }

    public function for(SocialAccount $account): AbstractPublicationHistoryCollector
    {
        return $this->accounts->uses($account)
            ? app(NativeLinkedInPagePublicationCollector::class)
            : $this->previous->for($account);
    }
}
