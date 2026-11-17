<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Console;

use App\Marmara\Inbox\InboxConfig;
use App\Marmara\Inbox\InboxProviders;
use App\Marmara\Inbox\InboxSync;
use App\Marmara\Inbox\Jobs\SyncInboxAccount;
use App\Models\SocialAccount;
use Illuminate\Console\Command;

/**
 * Scheduled every 5 minutes by the module: queues a sync for each channel,
 * provider and kind whose interval has passed and that is not backing off.
 * `--now` runs them here instead (for a manual check); `--force` ignores the
 * interval (not a back-off).
 */
class SyncInbox extends Command
{
    protected $signature = 'marmara:inbox:sync {--account= : Only this social account id} {--now : Run in this process instead of queueing} {--force : Ignore the polling interval}';

    protected $description = 'Read new comments and DMs of the channels the inbox providers serve (Marmara AI fork)';

    public function handle(InboxConfig $config, InboxProviders $providers, InboxSync $sync): int
    {
        if (! $config->enabled()) {
            $this->components->info('MARMARA_INBOX_ENABLED is off: nothing to do.');

            return self::SUCCESS;
        }

        $queued = 0;

        SocialAccount::query()
            ->when($this->option('account'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->each(function (SocialAccount $account) use ($providers, $sync, &$queued): void {
                $provider = $providers->for($account);

                // Native providers need a working connection; Zernio does not.
                if ($provider === null || ($provider->key() !== 'zernio' && $account->isDisconnected())) {
                    return;
                }

                foreach (array_keys($provider->capabilities($account)) as $kind) {
                    $state = $sync->state($account, $provider, $kind);

                    if ($state->isBlocked() || (! $this->option('force') && ! $sync->due($state))) {
                        continue;
                    }

                    if ($this->option('now')) {
                        $stored = $sync->run($account, $provider, $kind);
                        $state->refresh();
                        $this->line(sprintf('%s %s %s: %s, %d new%s', $account->display_name ?? $account->id, $provider->key(), $kind, $state->status, $stored, $state->status_detail ? " ({$state->status_detail})" : ''));
                    } else {
                        SyncInboxAccount::dispatch($account, $provider->key(), $kind);
                    }

                    $queued++;
                }
            });

        if (! $this->option('now')) {
            $this->components->info("Queued {$queued} inbox sync(s).");
        }

        return self::SUCCESS;
    }
}
