<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Analytics\Collectors;

use App\Dto\Analytics\PublicationPage;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Marmara\Zernio\Analytics\Concerns\ReadsZernioPublicationHistory;
use App\Marmara\Zernio\Analytics\ZernioPostAnalytics;
use App\Marmara\Zernio\ZernioConfig;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\AbstractPublicationHistoryCollector;
use Carbon\CarbonImmutable;

/**
 * LinkedIn page publication history read from Zernio (organization pages:
 * every post, published through Zernio or not). TryPost has no native
 * LinkedIn collector, so an unmapped page is refused as unsupported.
 */
class ZernioLinkedInPagePublicationCollector extends AbstractPublicationHistoryCollector
{
    use ReadsZernioPublicationHistory;

    public function __construct(
        private readonly ZernioConfig $config,
        private readonly ZernioPostAnalytics $analytics,
    ) {}

    public function page(SocialAccount $account, ?string $cursor, CarbonImmutable $cutoff): PublicationPage
    {
        $zernioAccountId = $this->config->analyticsAccountFor($account)
            ?? throw AnalyticsCollectionException::unsupported('LinkedIn page publication history needs a Zernio mapping');

        return $this->zernioPage($this->analytics, $account, $zernioAccountId, $cursor, $cutoff);
    }
}
