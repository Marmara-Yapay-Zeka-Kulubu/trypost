<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Console;

use App\Marmara\Inbox\InboxConfig;
use App\Marmara\Inbox\InboxProviders;
use App\Marmara\Inbox\InboxSync;
use App\Marmara\Inbox\Providers\LinkedInInboxProvider;
use App\Models\SocialAccount;
use Illuminate\Console\Command;

/**
 * Which provider reads each channel, what it can do there and where its
 * sync stands (including "needs permission X"). Makes no network call.
 */
class InboxStatus extends Command
{
    protected $signature = 'marmara:inbox:status {--account= : Only this social account id}';

    protected $description = 'Show the inbox provider, capabilities and sync status of every channel (Marmara AI fork)';

    public function handle(InboxConfig $config, InboxProviders $providers, InboxSync $sync): int
    {
        $this->line('MARMARA_INBOX_ENABLED: '.($config->enabled() ? 'on' : 'off'));

        foreach (InboxConfig::PROVIDERS as $key) {
            $this->line(sprintf('  %-9s %s', $key, $config->providerEnabled($key) ? 'on' : 'off'));
        }

        $this->line('LinkedIn inbox calls today: '.LinkedInInboxProvider::inboxCallsUsedToday().' / '.$config->linkedInDailyCalls());

        $rows = [];

        SocialAccount::query()
            ->when($this->option('account'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('platform')
            ->each(function (SocialAccount $account) use ($providers, $sync, &$rows): void {
                $provider = $providers->for($account);

                if ($provider === null) {
                    return;
                }

                foreach ($provider->capabilities($account) as $kind => $can) {
                    $state = $sync->state($account, $provider, $kind);
                    $replyMissing = $provider->missingReplyPermission($account, $kind);

                    $rows[] = [
                        $account->id,
                        $account->platform->value.' '.($account->username ?? $account->display_name),
                        $provider->key(),
                        $kind,
                        implode('/', array_keys(array_filter($can))),
                        $state->status.($state->blocked_until ? ' until '.$state->blocked_until->toDateTimeString() : ''),
                        $state->status_detail ?? ($replyMissing !== null ? "reply needs {$replyMissing}" : ''),
                        $state->last_synced_at?->toDateTimeString() ?? '-',
                    ];
                }
            });

        $this->table(['Channel', 'Account', 'Provider', 'Kind', 'Can', 'Status', 'Detail', 'Last sync'], $rows);

        return self::SUCCESS;
    }
}
