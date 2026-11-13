<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Console;

use App\Enums\SocialAccount\Status;
use App\Enums\TikTok\PrivacyLevel;
use App\Jobs\ResolveTikTokVideoId;
use App\Marmara\Zernio\ZernioConfig;
use App\Marmara\Zernio\ZernioTikTokPublisher;
use App\Models\Post;
use Illuminate\Console\Command;

/**
 * Upstream's social:resolve-tiktok-video-ids skips channels that are not
 * connected natively. A Zernio post does not need that connection, so this
 * sweep sends those posts to ResolveTikTokVideoId, which asks Zernio.
 */
class ResolveZernioVideoIds extends Command
{
    protected $signature = 'marmara:zernio:resolve-video-ids';

    protected $description = 'Ask Zernio for the TikTok video ids of Zernio posts whose native TikTok channel is not connected';

    /** Same window as upstream's sweep. */
    private const int RESOLVE_WITHIN_DAYS = 30;

    private const int RECHECK_AFTER_MINUTES = 10;

    public function handle(ZernioConfig $config): int
    {
        if (! $config->enabled()) {
            return self::SUCCESS;
        }

        $dispatched = 0;

        Post::query()
            ->publishedToTikTok()
            ->with('socialAccount')
            ->where('meta->privacy_level', PrivacyLevel::PublicToEveryone->value)
            ->where('platform_post_id', 'like', ZernioTikTokPublisher::POST_ID_PREFIX.'%')
            ->where('published_at', '>=', now()->subDays(self::RESOLVE_WITHIN_DAYS))
            ->lazyById()
            ->filter(fn (Post $post): bool => $post->awaitsTikTokVideoId()
                && $post->socialAccount !== null
                && $post->socialAccount->status !== Status::Connected
                && $config->zernioAccountFor($post->socialAccount) !== null
                && ($post->last_reconciled_at === null || $post->last_reconciled_at->lte(now()->subMinutes(self::RECHECK_AFTER_MINUTES))))
            ->each(function (Post $post) use (&$dispatched): void {
                ResolveTikTokVideoId::dispatch($post);
                $dispatched++;
            });

        $this->info("Dispatched {$dispatched} lookups.");

        return self::SUCCESS;
    }
}
