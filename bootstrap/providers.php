<?php

declare(strict_types=1);

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
];
