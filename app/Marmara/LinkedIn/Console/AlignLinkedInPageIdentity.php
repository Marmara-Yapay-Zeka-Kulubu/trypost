<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Console;

use App\Actions\Analytics\MoveAnalyticsIdentity;
use App\Enums\SocialAccount\Platform;
use App\Marmara\LinkedIn\NativeLinkedInAccounts;
use App\Marmara\Zernio\ZernioConfig;
use App\Models\SocialAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prepares a LinkedIn page channel created from Zernio (marmara:zernio:link)
 * for TryPost's native LinkedIn connection: its platform user id becomes the
 * organization id, the identity TryPost's LinkedIn OAuth stores for a page.
 * Then "Reconnect" on that channel (or a fresh connect of the page) updates
 * this same channel instead of creating a second one, and its analytics
 * history (publications, daily snapshots, sync states) moves with it through
 * TryPost's own MoveAnalyticsIdentity.
 */
class AlignLinkedInPageIdentity extends Command
{
    protected $signature = 'marmara:linkedin:align-page-id
        {account : TryPost channel id or username of the LinkedIn page}
        {--dry-run : Only show what would change}
        {--force : Change it even though a ZERNIO_ACCOUNTS entry keyed by the old platform user id would stop matching}';

    protected $description = 'Set a LinkedIn page channel\'s platform user id to its organization id, so a native connect updates it';

    public function handle(MoveAnalyticsIdentity $move): int
    {
        $key = ltrim(trim((string) $this->argument('account')), '@');
        $account = SocialAccount::query()
            ->where('platform', Platform::LinkedInPage)
            ->get()
            ->first(fn (SocialAccount $account): bool => $account->id === $key || strcasecmp(ltrim((string) $account->username, '@'), $key) === 0);

        if ($account === null) {
            $this->error("No LinkedIn page channel {$key}.");

            return self::FAILURE;
        }

        $organizationId = NativeLinkedInAccounts::organizationId($account);

        if ($organizationId === null) {
            $this->error('The channel has no meta.organization_id; re-run marmara:zernio:link or set it first.');

            return self::FAILURE;
        }

        $previous = (string) $account->platform_user_id;

        if ($previous === $organizationId) {
            $this->info("Already aligned: platform user id is {$organizationId}. A native connect of this page updates this channel.");

            return self::SUCCESS;
        }

        $duplicate = SocialAccount::query()
            ->where('workspace_id', $account->workspace_id)
            ->where('platform', Platform::LinkedInPage)
            ->where('platform_user_id', $organizationId)
            ->whereKeyNot($account->id)
            ->first();

        if ($duplicate !== null) {
            $this->error("Channel {$duplicate->id} already has platform user id {$organizationId} (a native connect created a second card). Remove that card in TryPost first (it deletes its own posts), then run this again and reconnect this channel.");

            return self::FAILURE;
        }

        if (! $this->option('force') && $this->mappingLost($account, $organizationId)) {
            $this->error('ZERNIO_ACCOUNTS maps this channel only by its old platform user id. Key the entry by the channel id ('.$account->id.') first, or pass --force.');

            return self::FAILURE;
        }

        $this->line("Channel {$account->id}: platform_user_id {$previous} -> {$organizationId}");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($account, $organizationId, $previous, $move): void {
            $account->forceFill(['platform_user_id' => $organizationId])->save();
            $move->handle($account, $previous);
        });

        $this->info('Aligned. Now use "Reconnect" on this LinkedIn page channel in TryPost (or connect the page) to store the native token on it.');

        return self::SUCCESS;
    }

    private function mappingLost(SocialAccount $account, string $organizationId): bool
    {
        if (! class_exists(ZernioConfig::class)) {
            return false;
        }

        $config = app(ZernioConfig::class);
        $before = $config->zernioAccountFor($account);
        $after = $config->zernioAccountFor((clone $account)->forceFill(['platform_user_id' => $organizationId]));

        return $before !== null && $after === null;
    }
}
