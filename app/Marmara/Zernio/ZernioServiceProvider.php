<?php

declare(strict_types=1);

namespace App\Marmara\Zernio;

use App\Marmara\Zernio\Console\ListZernioAccounts;
use App\Marmara\Zernio\Console\TestZernioConnection;
use App\Services\Social\TikTokPublisher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Marmara AI fork module: TikTok publishing through Zernio.
 *
 * TryPost resolves its TikTok publisher from the container
 * (PublishToSocialPlatform::getPublisher() and ResolveTikTokVideoId::handle()),
 * so the module swaps it here without editing upstream code. With
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
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ListZernioAccounts::class,
                TestZernioConnection::class,
            ]);
        }
    }
}
