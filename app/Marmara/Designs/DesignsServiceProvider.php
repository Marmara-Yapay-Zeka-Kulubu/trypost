<?php

declare(strict_types=1);

namespace App\Marmara\Designs;

use App\Marmara\Designs\Console\DesignsStatus;
use App\Marmara\Designs\Console\SyncDesigns;
use App\Marmara\Designs\Drive\DriveClient;
use App\Marmara\Designs\Drive\ServiceAccountToken;
use App\Marmara\Designs\Mcp\CommentOnDesignTool;
use App\Marmara\Designs\Mcp\GetDesignTool;
use App\Marmara\Designs\Mcp\ListDesignsTool;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignComment;
use App\Marmara\Designs\Support\DesignStatus;
use App\Marmara\Designs\Sync\DesignSync;
use App\Marmara\Designs\Thumbnails\ThumbnailCache;
use App\Marmara\Hooks\MarmaraMcpTools;
use App\Marmara\Hooks\MarmaraTryPostServer;
use App\Mcp\Servers\TryPostServer;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

/**
 * Marmara AI fork module: the "Tasarımlar" gallery of the club's shared
 * Google Drive folder (see app/Marmara/README.md, "Designs").
 *
 * Nothing upstream is rebound (TryPostServer only when the inbox module
 * has not already bound it). While MARMARA_DESIGNS_ENABLED is off the
 * routes answer 404, the schedule skips the sync, no webhook fires and the
 * MCP tools hide.
 */
class DesignsServiceProvider extends ServiceProvider
{
    /** Onaylandı / Kullanıldı need the approve right: one gate for the page, the API and MCP. */
    public const string STATUS_ABILITY = 'set-design-status';

    public const string COMMENT_UPDATE_ABILITY = 'update-design-comment';

    public const string COMMENT_DELETE_ABILITY = 'delete-design-comment';

    public function register(): void
    {
        $this->app->singleton(DesignsConfig::class);
        $this->app->singleton(ServiceAccountToken::class);
        $this->app->singleton(ThumbnailCache::class);
        $this->app->bind(DriveClient::class);
        $this->app->bind(DesignSync::class);

        // MCP: the inbox module binds TryPostServer to its server, which
        // extends MarmaraTryPostServer; without it this module binds the
        // shared one. Either way the tools below are added (and hide while
        // the module is off).
        MarmaraMcpTools::register([ListDesignsTool::class, GetDesignTool::class, CommentOnDesignTool::class]);

        if (! $this->app->bound(TryPostServer::class)) {
            $this->app->bind(TryPostServer::class, MarmaraTryPostServer::class);
        }
    }

    public function boot(): void
    {
        Gate::policy(Design::class, DesignPolicy::class);
        Gate::define(self::STATUS_ABILITY, fn (User $user, Design $design, DesignStatus|string $status): bool => (new DesignPolicy)->setStatus($user, $design, $status));
        Gate::define(self::COMMENT_UPDATE_ABILITY, fn (User $user, DesignComment $comment): bool => (new DesignPolicy)->updateComment($user, $comment));
        Gate::define(self::COMMENT_DELETE_ABILITY, fn (User $user, DesignComment $comment): bool => (new DesignPolicy)->deleteComment($user, $comment));

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/lang', 'marmara-designs');
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(SyncDesigns::class)
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->onOneServer()
                ->when(fn (): bool => $this->app->make(DesignsConfig::class)->enabled());
        });

        // The sidebar entry (resources/js/marmara/designs/nav.ts) reads this.
        Inertia::share('marmaraDesigns', function (): ?array {
            $user = Auth::user();

            if (! $this->app->make(DesignsConfig::class)->enabled() || ! $user instanceof User || $user->currentWorkspace === null) {
                return null;
            }

            return [
                'href' => route('marmara.designs.index'),
                'label' => DesignsTranslations::forLocale($this->app->getLocale())['title'] ?? 'Designs',
            ];
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                DesignsStatus::class,
                SyncDesigns::class,
            ]);
        }
    }
}
