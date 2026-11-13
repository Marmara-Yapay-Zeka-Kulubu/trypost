<?php

declare(strict_types=1);

namespace App\Marmara\Hooks;

/**
 * Marmara AI fork extension point in SocialAccount::scopeConnectedForAnalytics(),
 * the "connected" filter of the analytics jobs, dispatchers and reports.
 *
 * This default keeps upstream behaviour: only channels whose native
 * connection is `connected` are measured. A module that measures a channel
 * through another service rebinds this class in the container and names the
 * channels that service can still measure (see app/Marmara/README.md).
 */
class AnalyticsAccountGuard
{
    /**
     * Ids of channels measured although their native status is not
     * `connected`.
     *
     * @return list<string>
     */
    public function alsoUsableAccountIds(): array
    {
        return [];
    }
}
