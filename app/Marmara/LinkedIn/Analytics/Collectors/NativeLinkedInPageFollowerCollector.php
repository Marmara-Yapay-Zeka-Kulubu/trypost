<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Analytics\Collectors;

use App\Dto\Analytics\AccountDailyObservation;
use App\Marmara\LinkedIn\Analytics\Concerns\FallsBackForOtherAccounts;
use App\Marmara\LinkedIn\LinkedInApi;
use App\Marmara\LinkedIn\NativeLinkedInAccounts;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Followers\AbstractFollowerCollector;
use Carbon\CarbonImmutable;

/**
 * A LinkedIn page's daily follower count (networkSizes, one call a day).
 */
class NativeLinkedInPageFollowerCollector extends AbstractFollowerCollector
{
    use FallsBackForOtherAccounts;

    public function __construct(
        private readonly NativeLinkedInAccounts $accounts,
        private readonly LinkedInApi $api,
    ) {}

    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        $reason = $this->accounts->reason($account);

        if ($reason !== null) {
            /** @var AbstractFollowerCollector $previous */
            $previous = $this->previous($reason);

            return $previous->collect($account, $date);
        }

        return $this->observation($date, $this->api->followerCount($account));
    }
}
