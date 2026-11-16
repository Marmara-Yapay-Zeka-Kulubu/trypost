<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn;

use App\Marmara\Hooks\AnalyticsPlatformGate;
use App\Marmara\LinkedIn\Analytics\Factories\LinkedInFollowerCollectorFactory;
use App\Marmara\LinkedIn\Analytics\Factories\LinkedInPublicationHistoryCollectorFactory;
use App\Marmara\LinkedIn\Analytics\Factories\LinkedInPublicationMetricsCollectorFactory;
use App\Marmara\LinkedIn\Analytics\LinkedInAnalyticsPlatformGate;
use App\Marmara\LinkedIn\Console\AlignLinkedInPageIdentity;
use App\Marmara\LinkedIn\Console\BackfillLinkedInAnalytics;
use App\Marmara\LinkedIn\Console\TestLinkedInConnection;
use App\Services\Analytics\Collectors\Followers\FollowerCollectorFactory;
use App\Services\Analytics\Collectors\Metrics\PublicationMetricsCollectorFactory;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Marmara AI fork module: native LinkedIn page analytics (Community
 * Management API), next to the Zernio module.
 *
 * The three collector factories and the analytics platform gate are wrapped
 * with `extend()`, so whatever the container hands out without this module
 * (upstream's classes, or Zernio's rebinds, in any provider order) stays the
 * inner object: a natively measured LinkedIn page goes to the native
 * collectors, every other channel to the inner factory. With
 * MARMARA_LINKEDIN_NATIVE_ANALYTICS off the wrappers only delegate; the flag
 * is read on every call, not at boot.
 */
class LinkedInServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LinkedInConfig::class);
        $this->app->singleton(LinkedInCallBudget::class);

        $this->app->extend(PublicationHistoryCollectorFactory::class, fn (PublicationHistoryCollectorFactory $previous, Application $app): PublicationHistoryCollectorFactory => $app->make(LinkedInPublicationHistoryCollectorFactory::class, ['previous' => $previous]));

        $this->app->extend(PublicationMetricsCollectorFactory::class, fn (PublicationMetricsCollectorFactory $previous, Application $app): PublicationMetricsCollectorFactory => $app->make(LinkedInPublicationMetricsCollectorFactory::class, ['previous' => $previous]));

        $this->app->extend(FollowerCollectorFactory::class, fn (FollowerCollectorFactory $previous, Application $app): FollowerCollectorFactory => $app->make(LinkedInFollowerCollectorFactory::class, ['previous' => $previous]));

        $this->app->extend(AnalyticsPlatformGate::class, fn (AnalyticsPlatformGate $previous, Application $app): AnalyticsPlatformGate => $app->make(LinkedInAnalyticsPlatformGate::class, ['previous' => $previous]));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                AlignLinkedInPageIdentity::class,
                BackfillLinkedInAnalytics::class,
                TestLinkedInConnection::class,
            ]);
        }
    }
}
