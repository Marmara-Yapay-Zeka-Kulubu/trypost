<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Console;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Marmara\LinkedIn\Analytics\LinkedInPostMetrics;
use App\Marmara\LinkedIn\LinkedInApi;
use App\Marmara\LinkedIn\LinkedInCallBudget;
use App\Marmara\LinkedIn\LinkedInConfig;
use App\Marmara\LinkedIn\NativeLinkedInAccounts;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Checks a LinkedIn page channel's native connection: the token (never
 * printed), its scopes, the member's ADMINISTRATOR role on the page, the
 * follower count and the latest posts with their statistics. Works before
 * the Zernio mapping is removed, so the native side can be checked first.
 * About four LinkedIn calls, counted in the daily budget.
 */
class TestLinkedInConnection extends Command
{
    /** Scope => what it is used for. */
    private const array SCOPES = [
        'rw_organization_admin' => 'post statistics, follower statistics, admin role',
        'r_organization_social' => 'post list (history), video views',
        'w_organization_social' => 'publishing to the page',
        'r_organization_followers' => 'follower data (optional)',
        'r_organization_social_feed' => 'comments / reactions feed (optional, not used yet)',
    ];

    protected $signature = 'marmara:linkedin:test
        {--account= : TryPost channel id, username or LinkedIn organization id}
        {--posts=5 : How many of the latest posts to show}';

    protected $description = 'Check a LinkedIn page channel\'s native token, scopes, admin role, followers and latest posts';

    public function handle(LinkedInConfig $config, NativeLinkedInAccounts $accounts, LinkedInApi $api, LinkedInPostMetrics $metrics, LinkedInCallBudget $budget): int
    {
        $this->line('Native LinkedIn analytics: '.($config->enabled() ? 'on' : 'off (MARMARA_LINKEDIN_NATIVE_ANALYTICS=false)'));
        $this->line("LinkedIn-Version: {$config->version()}, calls used today: {$budget->used()} of {$config->dailyCallBudget()}");

        $channels = $this->channels();

        if ($channels->isEmpty()) {
            $this->error('No LinkedIn page channel'.(filled($this->option('account')) ? ' matches --account.' : ' exists.'));

            return self::FAILURE;
        }

        $this->table(
            ['Channel', 'Username', 'Organization', 'Status', 'Native token', 'Refresh token', 'Token expires', 'Analytics'],
            $channels->map(fn (SocialAccount $account): array => [
                $account->id,
                (string) $account->username,
                NativeLinkedInAccounts::organizationId($account) ?? '-',
                $account->status->value,
                NativeLinkedInAccounts::hasNativeToken($account) ? 'yes' : 'no',
                filled($account->refresh_token) ? 'yes' : 'no',
                $account->token_expires_at?->toDateTimeString() ?? '-',
                $accounts->reason($account) === null ? 'native' : 'not native: '.$accounts->reason($account),
            ])->all(),
        );

        $account = $channels->first(fn (SocialAccount $account): bool => NativeLinkedInAccounts::hasNativeToken($account) && NativeLinkedInAccounts::organizationId($account) !== null);

        if ($account === null) {
            $this->error('No channel has a native LinkedIn token and an organization id: connect the page through TryPost (Channels > LinkedIn) first.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Testing {$account->id} (".NativeLinkedInAccounts::organizationUrn($account).')');

        if (blank($account->refresh_token)) {
            $this->warn('No refresh token is stored: LinkedIn gave this app none, so the page must be reconnected before the token expires (60 days).');
        }

        $this->scopes($account);
        $ok = true;

        $ok = $this->attempt('Admin role', function () use ($api, $account): void {
            $urn = NativeLinkedInAccounts::organizationUrn($account);
            $organizations = $api->administeredOrganizations($account);

            if (! in_array($urn, $organizations, true)) {
                throw AnalyticsCollectionException::unsupported("the token's member is not an approved ADMINISTRATOR of {$urn}");
            }

            $this->line("  Admin role: ADMINISTRATOR of {$urn}");
        }) && $ok;

        $ok = $this->attempt('Followers', function () use ($api, $account): void {
            $this->line('  Followers: '.number_format($api->followerCount($account)));
        }) && $ok;

        $ok = $this->attempt('Latest posts', function () use ($api, $metrics, $account): void {
            $posts = $api->posts($account, 0, min(20, max(1, (int) $this->option('posts'))))['elements'];
            $urns = array_values(array_filter(array_map(fn (array $post): mixed => data_get($post, 'id'), $posts), 'is_string'));
            $statistics = $metrics->prefetch($account, $urns);

            $this->table(
                ['Post', 'Published', 'Impressions', 'Reach', 'Clicks', 'Reactions', 'Comments', 'Shares'],
                array_map(function (array $post) use ($statistics): array {
                    $urn = (string) data_get($post, 'id');
                    $values = collect(app(LinkedInPostMetrics::class)->metrics($statistics[$urn] ?? []))
                        ->mapWithKeys(fn ($metric): array => [$metric->key->value => $metric->value]);
                    $published = data_get($post, 'publishedAt') ?? data_get($post, 'createdAt');

                    return [
                        $urn,
                        is_numeric($published) ? CarbonImmutable::createFromTimestampMsUTC((int) $published)->toDateTimeString() : '-',
                        ...array_map(fn (string $key): string => (string) ($values[$key] ?? '-'), ['impressions', 'reach', 'clicks', 'reactions', 'comments', 'shares']),
                    ];
                }, $posts),
            );
        }) && $ok;

        $this->line("Calls used today: {$budget->used()} of {$config->dailyCallBudget()}");

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return Collection<int, SocialAccount>
     */
    private function channels(): Collection
    {
        $only = ltrim(trim((string) $this->option('account')), '@');

        return SocialAccount::query()
            ->where('platform', Platform::LinkedInPage)
            ->get()
            ->filter(fn (SocialAccount $account): bool => $only === ''
                || $account->id === $only
                || NativeLinkedInAccounts::organizationId($account) === $only
                || strcasecmp(ltrim((string) $account->username, '@'), $only) === 0)
            ->values();
    }

    private function scopes(SocialAccount $account): void
    {
        $granted = (array) $account->scopes;

        if ($granted === []) {
            $this->warn('  Scopes: not recorded on the channel (LinkedIn decides per call).');

            return;
        }

        foreach (self::SCOPES as $scope => $use) {
            $has = in_array($scope, $granted, true);
            $line = sprintf('  %s %s: %s', $has ? '[x]' : '[ ]', $scope, $use);
            $has ? $this->line($line) : $this->warn($line);
        }
    }

    /**
     * @param  Closure(): void  $check
     */
    private function attempt(string $label, Closure $check): bool
    {
        try {
            $check();

            return true;
        } catch (AnalyticsCollectionException $exception) {
            $this->error("  {$label}: {$exception->category}: {$exception->getMessage()}");

            return false;
        }
    }
}
