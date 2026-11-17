<?php

declare(strict_types=1);

namespace App\Marmara\Inbox;

use App\Http\Controllers\Auth\FacebookController;
use App\Http\Controllers\Auth\InstagramController;
use App\Http\Controllers\Auth\InstagramFacebookController;
use App\Http\Controllers\Auth\XController;
use App\Marmara\Inbox\Connect\InboxFacebookController;
use App\Marmara\Inbox\Connect\InboxInstagramController;
use App\Marmara\Inbox\Connect\InboxInstagramFacebookController;
use App\Marmara\Inbox\Connect\InboxXController;
use App\Marmara\Inbox\Console\InboxStatus;
use App\Marmara\Inbox\Console\SyncInbox;
use App\Marmara\Inbox\Mcp\InboxTryPostServer;
use App\Marmara\Inbox\Models\InboxItem;
use App\Mcp\Servers\TryPostServer;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

/**
 * Marmara AI fork module: one inbox for the comments and DMs of the club's
 * channels (see app/Marmara/README.md, "Inbox").
 *
 * Nothing upstream is rebound while MARMARA_INBOX_ENABLED is off except
 * classes that then behave exactly like upstream's: the four connect
 * controllers add scopes only while their provider is on, and the MCP
 * server's inbox tools hide themselves (shouldRegister()). The routes are
 * always registered and answer 404 while the flag is off.
 */
class InboxServiceProvider extends ServiceProvider
{
    public const string REPLY_ABILITY = 'reply-to-inbox-item';

    public function register(): void
    {
        $this->app->singleton(InboxConfig::class);
        $this->app->singleton(InboxProviders::class);

        // TryPost resolves controllers and the MCP server through the
        // container, so subclasses replace them without editing upstream.
        foreach ([
            FacebookController::class => InboxFacebookController::class,
            InstagramController::class => InboxInstagramController::class,
            InstagramFacebookController::class => InboxInstagramFacebookController::class,
            XController::class => InboxXController::class,
            TryPostServer::class => InboxTryPostServer::class,
        ] as $upstream => $marmara) {
            $this->app->bind($upstream, $marmara);
        }
    }

    public function boot(): void
    {
        // One rule for the panel, the API and MCP: a reply (or a hide) goes
        // out without approval, so it needs the workspace's direct publishing
        // right, like publishing a post (WorkspacePolicy::publishDirectly).
        Gate::define(self::REPLY_ABILITY, fn (User $user, InboxItem $item): bool => $item->workspace !== null
            && $user->can('view', $item->workspace)
            && $user->can('publishDirectly', $item->workspace));

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/lang', 'marmara-inbox');
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(SyncInbox::class)
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->onOneServer()
                ->when(fn (): bool => $this->app->make(InboxConfig::class)->enabled());
        });

        // The sidebar entry (resources/js/marmara/inbox/nav.ts) reads this.
        Inertia::share('marmaraInbox', function (): ?array {
            $config = $this->app->make(InboxConfig::class);
            $user = Auth::user();

            if (! $config->enabled() || ! $user instanceof User || $user->currentWorkspace === null) {
                return null;
            }

            return [
                'href' => route('marmara.inbox.index'),
                'label' => InboxTranslations::forLocale($this->app->getLocale())['title'] ?? 'Inbox',
                'unread' => InboxItem::query()->where('workspace_id', $user->currentWorkspace->id)->status('unread')->count(),
            ];
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                InboxStatus::class,
                SyncInbox::class,
            ]);
        }
    }
}
