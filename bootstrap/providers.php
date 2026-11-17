<?php

declare(strict_types=1);

use App\Marmara\Inbox\InboxServiceProvider;
use App\Marmara\LinkedIn\LinkedInServiceProvider;
use App\Marmara\Portal\PortalServiceProvider;
use App\Marmara\Zernio\ZernioServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\PassportServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    PassportServiceProvider::class,
    // Marmara AI fork: TikTok publishing through Zernio (app/Marmara/README.md).
    ZernioServiceProvider::class,
    // Marmara AI fork: default language and guest branding (app/Marmara/README.md).
    PortalServiceProvider::class,
    // Marmara AI fork: native LinkedIn page analytics (app/Marmara/README.md).
    LinkedInServiceProvider::class,
    // Marmara AI fork: comments and DMs inbox (app/Marmara/README.md).
    InboxServiceProvider::class,
];
