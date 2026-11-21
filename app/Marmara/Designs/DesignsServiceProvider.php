<?php

declare(strict_types=1);

namespace App\Marmara\Designs;

use App\Marmara\Designs\Console\DesignsStatus;
use App\Marmara\Designs\Console\SyncDesigns;
use App\Marmara\Designs\Drive\DriveClient;
use App\Marmara\Designs\Drive\ServiceAccountToken;
use App\Marmara\Designs\Sync\DesignSync;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Marmara AI fork module: the "Tasarımlar" gallery of the club's shared
 * Google Drive folder (see app/Marmara/README.md, "Designs").
 *
 * Nothing upstream is rebound. While MARMARA_DESIGNS_ENABLED is off the
 * routes answer 404, the schedule skips the sync and the MCP tools hide.
 */
class DesignsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DesignsConfig::class);
        $this->app->singleton(ServiceAccountToken::class);
        $this->app->bind(DriveClient::class);
        $this->app->bind(DesignSync::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(SyncDesigns::class)
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->onOneServer()
                ->when(fn (): bool => $this->app->make(DesignsConfig::class)->enabled());
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                DesignsStatus::class,
                SyncDesigns::class,
            ]);
        }
    }
}
