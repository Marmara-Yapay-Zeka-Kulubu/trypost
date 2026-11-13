<?php

declare(strict_types=1);

namespace App\Marmara\Zernio;

use App\Marmara\Hooks\SocialAccountPublishGuard;
use App\Marmara\Zernio\Console\ListZernioAccounts;
use App\Marmara\Zernio\Console\ReleaseZernioPublication;
use App\Marmara\Zernio\Console\ResolveZernioVideoIds;
use App\Marmara\Zernio\Console\TestZernioConnection;
use App\Services\Social\TikTokCreatorInfo;
use App\Services\Social\TikTokPublisher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Marmara AI fork module: TikTok publishing through Zernio.
 *
 * TryPost resolves its TikTok publisher from the container
 * (PublishToSocialPlatform::getPublisher() and ResolveTikTokVideoId::handle()),
 * so the module swaps it here without editing upstream code. The publish
 * job's channel check goes through SocialAccountPublishGuard (the one hook
 * this fork adds to PublishToSocialPlatform), rebound the same way. With
 * ZERNIO_ENABLED off the container builds the plain TikTokPublisher, as if
 * this provider did not exist. The flag is read when the publisher is
 * resolved, not when the app boots.
 */
class ZernioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ZernioConfig::class);

        $this->app->bind(TikTokPublisher::class, function (Application $app): TikTokPublisher {
            return $app->make(ZernioConfig::class)->enabled()
                ? $app->make(ZernioTikTokPublisher::class)
                : $app->build(TikTokPublisher::class);
        });

        // The composer's TikTok options of a Zernio-mapped channel come from Zernio.
        $this->app->bind(TikTokCreatorInfo::class, function (Application $app): TikTokCreatorInfo {
            return $app->make(ZernioConfig::class)->enabled()
                ? $app->make(ZernioTikTokCreatorInfo::class)
                : $app->build(TikTokCreatorInfo::class);
        });

        // A Zernio-mapped channel is checked by Zernio, not its native token;
        // every other channel keeps the default (upstream) checks.
        $this->app->bind(SocialAccountPublishGuard::class, function (Application $app): SocialAccountPublishGuard {
            return $app->make(ZernioConfig::class)->enabled()
                ? $app->make(ZernioPublishGuard::class)
                : $app->build(SocialAccountPublishGuard::class);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        // Upstream's social:resolve-tiktok-video-ids only looks at connected
        // channels; this one covers Zernio posts whose native channel is not.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(ResolveZernioVideoIds::class)->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                ListZernioAccounts::class,
                ReleaseZernioPublication::class,
                ResolveZernioVideoIds::class,
                TestZernioConnection::class,
            ]);
        }
    }
}
