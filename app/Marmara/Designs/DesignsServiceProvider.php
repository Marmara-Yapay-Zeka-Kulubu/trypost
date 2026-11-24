<?php

declare(strict_types=1);

namespace App\Marmara\Designs;

use App\Enums\Post\PublishStatus;
use App\Marmara\Designs\Console\DesignsStatus;
use App\Marmara\Designs\Console\SyncDesigns;
use App\Marmara\Designs\Drive\DriveClient;
use App\Marmara\Designs\Drive\ServiceAccountToken;
use App\Marmara\Designs\Mcp\AddDesignTool;
use App\Marmara\Designs\Mcp\CommentOnDesignTool;
use App\Marmara\Designs\Mcp\GetDesignTool;
use App\Marmara\Designs\Mcp\ListDesignsTool;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignComment;
use App\Marmara\Designs\Posts\DesignPostLinks;
use App\Marmara\Designs\Posts\RecordDesignPublication;
use App\Marmara\Designs\Support\DesignStatus;
use App\Marmara\Designs\Sync\DesignSync;
use App\Marmara\Designs\Thumbnails\ThumbnailCache;
use App\Marmara\Hooks\MarmaraMcpTools;
use App\Marmara\Hooks\MarmaraTryPostServer;
use App\Mcp\Servers\TryPostServer;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        $this->app->bind(DesignPostLinks::class);
        $this->app->bind(RecordDesignPublication::class);

        // MCP: the inbox module binds TryPostServer to its server, which
        // extends MarmaraTryPostServer; without it this module binds the
        // shared one. Either way the tools below are added (and hide while
        // the module is off).
        MarmaraMcpTools::register([ListDesignsTool::class, GetDesignTool::class, CommentOnDesignTool::class, AddDesignTool::class]);

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

        $this->listenToPosts();

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/lang', 'marmara-designs');
        $this->loadViewsFrom(__DIR__.'/resources/views', 'marmara-designs');
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
            $config = $this->app->make(DesignsConfig::class);
            $workspace = $config->enabled() ? $config->workspace() : null;

            // Only members of the designs workspace see the entry.
            if ($workspace === null || ! $user instanceof User || ! $user->can('view', $workspace)) {
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

    /**
     * Designs on posts, without touching TryPost's post code: model events
     * of Media and Post (app/Marmara/README.md, Designs, "Designs on posts").
     *
     * - A media row that lands on a post (created as a channel's copy, or a
     *   temporary upload adopted by the post) and holds a design's file
     *   links the post to the design, in the same transaction.
     * - A post whose publish_status moves into a went-out state (published,
     *   or deleted on the network since; PublishStatus::wentOut()) from one
     *   that was not records the publication on its designs after commit,
     *   and queues adding its other images and videos to the gallery.
     *   Post::markPublicationPublished() writes publish_status with a plain
     *   save, so this `updated` event is the one signal every publisher
     *   (native, Zernio, reconcile) goes through. rescue(): a designs
     *   problem never fails a publication.
     *
     * Both return at once while the module is off.
     */
    private function listenToPosts(): void
    {
        Media::saved(function (Media $media): void {
            if ($media->post_id !== null) {
                rescue(fn () => $this->app->make(DesignPostLinks::class)->mediaSaved($media), report: true);
            }
        });

        Post::updated(function (Post $post): void {
            if (! $post->wasChanged('publish_status') || ! $post->publish_status instanceof PublishStatus || ! $post->publish_status->wentOut()) {
                return;
            }

            $previous = $post->getOriginal('publish_status');
            $previous = $previous instanceof PublishStatus ? $previous : PublishStatus::tryFrom((string) $previous);

            if ($previous?->wentOut() === true || ! $this->app->make(DesignsConfig::class)->enabled()) {
                return;
            }

            $postId = $post->id;

            DB::afterCommit(fn () => rescue(function () use ($postId): void {
                $fresh = Post::query()->find($postId);

                if ($fresh !== null) {
                    $this->app->make(RecordDesignPublication::class)->postWentOut($fresh);
                }
            }, report: true));
        });
    }
}
