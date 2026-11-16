<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Connect;

use SocialiteProviders\Manager\SocialiteWasCalled;

/**
 * Registers BasicProfileLinkedInProvider as the `linkedin-openid` driver.
 * The module's provider listens after AppServiceProvider (it comes later in
 * bootstrap/providers.php), so this extension replaces upstream's
 * LinkedInPageExtendSocialite. The driver behaves exactly like upstream's
 * while MARMARA_LINKEDIN_PAGE_BASIC_PROFILE is off.
 */
class LinkedInBasicProfileExtendSocialite
{
    public function handle(SocialiteWasCalled $socialiteWasCalled): void
    {
        $socialiteWasCalled->extendSocialite('linkedin-openid', BasicProfileLinkedInProvider::class);
    }
}
