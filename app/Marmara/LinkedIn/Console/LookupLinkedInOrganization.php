<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Console;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Marmara\LinkedIn\LinkedInConfig;
use App\Marmara\LinkedIn\Mentions\LinkedInOrganizationResolver;
use App\Marmara\LinkedIn\NativeLinkedInAccounts;
use App\Models\SocialAccount;
use App\Support\Analytics\AnalyticsAccessToken;
use Illuminate\Console\Command;

/**
 * Checks that a company mention resolves with a real channel token: the
 * same lookup the LinkedIn publishers make (`GET /rest/organizations?q=vanityName`),
 * without the cache. Prints the organization id and name, or LinkedIn's HTTP
 * status and reason (a 403 means the club's LinkedIn app or the token lacks
 * the permission, and mentions publish as plain names). One call, counted in
 * the daily budget. The token is never printed.
 */
class LookupLinkedInOrganization extends Command
{
    protected $signature = 'marmara:linkedin-org
        {vanity : The company page\'s vanity name or link (linkedin.com/company/<vanity>)}
        {--account= : TryPost channel id, username or LinkedIn organization id whose token is used (default: the first LinkedIn page with a native token)}';

    protected $description = 'Resolve a LinkedIn company page vanity name to its organization id, as company mentions do';

    public function handle(LinkedInConfig $config, LinkedInOrganizationResolver $resolver): int
    {
        $vanity = $this->vanity((string) $this->argument('vanity'));

        if ($vanity === '') {
            $this->error('Give a vanity name or a linkedin.com/company/<vanity> link.');

            return self::FAILURE;
        }

        $account = $this->account();

        if ($account === null) {
            $this->error('No LinkedIn channel with a native token'.(filled($this->option('account')) ? ' matches --account.' : ' exists: connect the LinkedIn page through TryPost first.'));

            return self::FAILURE;
        }

        $this->line("Channel: {$account->id} ({$account->platform->value}, ".($account->username ?? '-').')');
        $this->line("GET {$resolver->url($vanity)}");
        $this->line("LinkedIn-Version: {$config->version()}, X-Restli-Protocol-Version: 2.0.0");

        try {
            $token = AnalyticsAccessToken::for($account);
        } catch (AnalyticsCollectionException $exception) {
            $this->error("Token: {$exception->category}: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $lookup = $resolver->lookup($vanity, $token);
        // A found organization replaces the cached one for every channel; a failure is cached for this channel.
        $resolver->remember($vanity, $lookup, $token, $account->id);

        if (! $lookup->resolved()) {
            $this->error('Not resolved'.($lookup->status !== null ? " (HTTP {$lookup->status})" : '').": {$lookup->reason}");
            $this->line('Mentions of this company publish as the plain display name.');

            return self::FAILURE;
        }

        $this->info("Organization: {$lookup->urn()}");
        $this->line('Name: '.($lookup->name ?? '-'));
        $this->line('Mention: @['.($lookup->name ?? $vanity)."]({$lookup->urn()})");

        return self::SUCCESS;
    }

    private function vanity(string $input): string
    {
        $input = trim($input);

        if (preg_match('~linkedin\.com/company/([^\s/?#()\[\]]+)~i', $input, $matches) === 1) {
            $input = $matches[1];
        }

        return mb_strtolower(rawurldecode(trim($input, " /\t\n\r\0\x0B")));
    }

    private function account(): ?SocialAccount
    {
        $only = ltrim(trim((string) $this->option('account')), '@');

        return SocialAccount::query()
            ->whereIn('platform', [Platform::LinkedInPage, Platform::LinkedIn])
            ->get()
            ->filter(fn (SocialAccount $account): bool => NativeLinkedInAccounts::hasNativeToken($account))
            ->filter(fn (SocialAccount $account): bool => $only === ''
                || $account->id === $only
                || NativeLinkedInAccounts::organizationId($account) === $only
                || strcasecmp(ltrim((string) $account->username, '@'), $only) === 0)
            ->sortBy(fn (SocialAccount $account): int => $account->platform === Platform::LinkedInPage ? 0 : 1)
            ->first();
    }
}
