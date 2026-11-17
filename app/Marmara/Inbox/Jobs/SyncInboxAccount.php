<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Jobs;

use App\Marmara\Inbox\InboxProviders;
use App\Marmara\Inbox\InboxSync;
use App\Models\SocialAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reads one channel's new comments or DMs. Unique per channel, provider and
 * kind, so a slow run never overlaps the next one.
 */
class SyncInboxAccount implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct(
        public SocialAccount $account,
        public string $provider,
        public string $kind,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->account->id}:{$this->provider}:{$this->kind}";
    }

    public function handle(InboxProviders $providers, InboxSync $sync): void
    {
        $provider = $providers->for($this->account);

        // The flag or the mapping changed since the job was queued.
        if ($provider === null || $provider->key() !== $this->provider) {
            return;
        }

        $sync->run($this->account, $provider, $this->kind);
    }
}
