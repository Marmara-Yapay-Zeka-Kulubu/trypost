<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Console;

use App\Actions\Analytics\DispatchAccountAnalytics;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Marmara\Zernio\Exceptions\ZernioPublishException;
use App\Marmara\Zernio\ZernioAccountHealth;
use App\Marmara\Zernio\ZernioClient;
use App\Marmara\Zernio\ZernioConfig;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;

/**
 * Creates (or refreshes) the TryPost channel of an account connected only to
 * Zernio, so posts can target it and analytics measure it, without a native
 * connection (the club's own LinkedIn / TikTok apps lack the scopes).
 *
 * The channel is `connected` with no native token (`meta.managed_by=zernio`):
 * ZernioConnectionVerifier answers its connection checks from Zernio and
 * never refreshes it, and it has no token_expires_at, so the token refresh
 * sweep skips it. An existing channel (`--account`, or the same username on
 * the platform in the workspace) is updated and marked connected instead.
 * The command prints the ZERNIO_ACCOUNTS entry to add; it does not edit .env.
 */
class LinkZernioAccount extends Command
{
    protected $signature = 'marmara:zernio:link
        {zernioAccountId : The Zernio account id (marmara:zernio:accounts)}
        {--workspace= : TryPost workspace id (needed when there is more than one)}
        {--account= : Update this existing TryPost channel (id) instead of finding or creating one}';

    protected $description = 'Create or update the TryPost channel of an account connected only to Zernio (TikTok, LinkedIn page)';

    public function handle(ZernioClient $client): int
    {
        $zernioAccountId = trim((string) $this->argument('zernioAccountId'));

        try {
            $response = $client->listAccounts();
        } catch (ZernioPublishException $e) {
            $this->error($e->userMessage);

            return self::FAILURE;
        } catch (ConnectionException $e) {
            $this->error('Zernio did not answer: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error("Zernio answered HTTP {$response->status()}.");

            return self::FAILURE;
        }

        $zernio = collect((array) data_get($response->json(), 'accounts', []))
            ->first(fn (mixed $account): bool => is_array($account) && (string) (data_get($account, '_id') ?? data_get($account, 'id')) === $zernioAccountId);

        if (! is_array($zernio)) {
            $this->error("Zernio does not list the account {$zernioAccountId} for this API key.");

            return self::FAILURE;
        }

        $platform = match (strtolower((string) data_get($zernio, 'platform'))) {
            'tiktok' => Platform::TikTok,
            'linkedin' => Platform::LinkedInPage,
            default => null,
        };

        if ($platform === null) {
            $this->error('Only TikTok and LinkedIn page accounts can be linked, this one is '.data_get($zernio, 'platform').'.');

            return self::FAILURE;
        }

        // Only a company page becomes a LinkedIn page channel: a member's
        // personal profile is never linked as one.
        if ($platform === Platform::LinkedInPage && ZernioAccountHealth::isLinkedInOrganization($zernio) !== true) {
            $this->error(ZernioAccountHealth::isLinkedInOrganization($zernio) === false
                ? "The Zernio account {$zernioAccountId} is a personal LinkedIn profile, not a company page. Switch it to the page in Zernio (organization posting) or connect the page."
                : "Zernio does not show the account {$zernioAccountId} as a LinkedIn company page, so it is not linked. Switch it to the page in Zernio (organization posting) first.");

            return self::FAILURE;
        }

        $workspace = $this->workspace();

        if ($workspace === null) {
            return self::FAILURE;
        }

        $username = ltrim((string) data_get($zernio, 'username', ''), '@');
        $account = $this->existing($workspace, $platform, $username);

        if ($account === false) {
            return self::FAILURE;
        }

        $picture = data_get($zernio, 'profilePicture');
        $values = array_filter([
            'username' => $username !== '' ? $username : null,
            'display_name' => data_get($zernio, 'displayName') ?: null,
            // Copied to TryPost's storage like a native connect does: the
            // column holds a storage path. A failed download leaves it as is.
            'avatar_url' => is_string($picture) && $picture !== '' ? uploadFromUrl($picture) : null,
        ], fn (mixed $value): bool => is_string($value) && $value !== '');
        $meta = array_filter([
            'zernio_account_id' => $zernioAccountId,
            'organization_id' => $platform === Platform::LinkedInPage ? $this->organizationId($zernio) : null,
        ], fn (mixed $value): bool => $value !== null);

        if ($account !== null) {
            $account->fill([...$values, 'meta' => [...($account->meta ?? []), ...$meta]])->save();
            $account->markAsConnected();
            $this->info("Updated TryPost channel {$account->id} ({$platform->label()}) and marked it connected.");
        } else {
            $platformUserId = (string) (data_get($zernio, 'platformUserId') ?: 'zernio:'.$zernioAccountId);
            $account = SocialAccount::query()->create([
                ...$values,
                'workspace_id' => $workspace->id,
                'platform' => $platform,
                'platform_user_id' => $platformUserId,
                // No native token: the column is required, the value is never used.
                'access_token' => 'zernio-managed:'.Str::random(32),
                'refresh_token' => null,
                'token_expires_at' => null,
                'scopes' => null,
                'meta' => [...$meta, 'managed_by' => 'zernio'],
                'status' => Status::Connected,
            ]);
            $this->info("Created TryPost channel {$account->id} ({$platform->label()}) for Zernio account {$zernioAccountId}.");
        }

        ZernioAccountHealth::forget($zernioAccountId);
        app(DispatchAccountAnalytics::class)->handle($account);

        $mapped = app(ZernioConfig::class)->zernioAccountFor($account) === $zernioAccountId;
        $entry = "{$platform->value}:{$account->id}={$zernioAccountId}";

        if ($mapped) {
            $this->line('Already mapped in ZERNIO_ACCOUNTS.');
        } else {
            $this->warn('Add this entry to ZERNIO_ACCOUNTS in .env (comma-separated with the others), then recreate the app container:');
            $this->line($entry);
        }

        return self::SUCCESS;
    }

    private function workspace(): ?Workspace
    {
        $id = trim((string) $this->option('workspace'));

        if ($id !== '') {
            $workspace = Workspace::query()->find($id);

            if ($workspace === null) {
                $this->error("No workspace {$id}.");
            }

            return $workspace;
        }

        $workspaces = Workspace::query()->limit(2)->get();

        if ($workspaces->count() === 1) {
            return $workspaces->first();
        }

        $this->error('Pass --workspace=<id>. Workspaces:');

        foreach (Workspace::query()->get(['id', 'name']) as $workspace) {
            $this->line("  {$workspace->id}  {$workspace->name}");
        }

        return null;
    }

    /**
     * The channel to update: `--account`, else the workspace's channel of the
     * platform with the same username. False when `--account` is wrong.
     */
    private function existing(Workspace $workspace, Platform $platform, string $username): SocialAccount|false|null
    {
        $id = trim((string) $this->option('account'));

        if ($id !== '') {
            $account = SocialAccount::query()->where('workspace_id', $workspace->id)->where('platform', $platform)->find($id);

            if ($account === null) {
                $this->error("No {$platform->label()} channel {$id} in this workspace.");

                return false;
            }

            return $account;
        }

        if ($username === '') {
            return null;
        }

        return SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->where('platform', $platform)
            ->whereRaw('lower(username) = ?', [strtolower($username)])
            ->first();
    }

    /**
     * The LinkedIn organization id, when Zernio reports one (`urn:li:organization:{id}`).
     *
     * @param  array<string, mixed>  $zernio
     */
    private function organizationId(array $zernio): ?string
    {
        foreach ([data_get($zernio, 'metadata.organizationUrn'), data_get($zernio, 'metadata.organizationId'), data_get($zernio, 'platformUserId')] as $value) {
            if (is_scalar($value) && preg_match('/^(?:urn:li:organization:)?(\d+)$/', (string) $value, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }
}
