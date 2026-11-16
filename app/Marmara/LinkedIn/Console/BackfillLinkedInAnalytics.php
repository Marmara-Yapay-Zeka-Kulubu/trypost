<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Console;

use App\Actions\Analytics\AdvanceAnalyticsSyncState;
use App\Actions\Analytics\QueuePublicationMetricsForPage;
use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Actions\Analytics\WriteAccountDailySnapshot;
use App\Actions\Post\ImportExternalPosts;
use App\Dto\Analytics\AccountDailyObservation;
use App\Enums\Analytics\MetricPrecision;
use App\Enums\Analytics\ObservationProvenance;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Jobs\Post\ImportExternalPostMedia;
use App\Marmara\LinkedIn\LinkedInApi;
use App\Marmara\LinkedIn\LinkedInCallBudget;
use App\Marmara\LinkedIn\LinkedInConfig;
use App\Marmara\LinkedIn\NativeLinkedInAccounts;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

/**
 * One-off historical import for the natively measured LinkedIn pages, the
 * same way marmara:zernio:analytics-backfill does it for Zernio: the
 * account's publication backfill (TryPost's own sync state) is restarted from
 * `--days` ago and run here page by page through the normal pipeline (upsert,
 * first metric reads queued, external posts imported into Sent), then the
 * daily follower history is derived from LinkedIn's daily follower gains.
 *
 * It stays inside the daily call budget minus MARMARA_LINKEDIN_BACKFILL_RESERVE.
 * When the budget or LinkedIn's limit stops it, the cursor is kept: run it
 * again with --resume (the next UTC day) to continue where it stopped.
 */
class BackfillLinkedInAnalytics extends Command
{
    private const int MAX_PAGES = 50;

    private const int MAX_SHORT_WAITS = 3;

    private const int SHORT_WAIT_SECONDS = 120;

    protected $signature = 'marmara:linkedin:analytics-backfill
        {--days=365 : How far back to import (LinkedIn keeps post statistics for 12 months)}
        {--account= : Only this TryPost channel (id, username or organization id)}
        {--import-limit= : Newest unlinked publications to import into Sent (default MARMARA_LINKEDIN_IMPORT_LIMIT)}
        {--skip-followers : Do not write the daily follower history}
        {--resume : Continue a backfill the budget or a rate limit stopped, instead of restarting it}';

    protected $description = 'Import the post history, metrics and follower history of the natively measured LinkedIn pages';

    public function handle(
        LinkedInConfig $config,
        NativeLinkedInAccounts $native,
        LinkedInCallBudget $budget,
        AdvanceAnalyticsSyncState $sync,
        PublicationHistoryCollectorFactory $collectors,
        QueuePublicationMetricsForPage $metrics,
        LinkedInApi $api,
        WriteAccountDailySnapshot $writer,
        ResolveAnalyticsAccountKey $accountKeys,
    ): int {
        if (! $config->enabled()) {
            $this->error('Native LinkedIn analytics are off: set MARMARA_LINKEDIN_NATIVE_ANALYTICS=true.');

            return self::FAILURE;
        }

        $budget->forBackfill();
        $days = max(1, (int) $this->option('days'));
        $importLimit = $this->option('import-limit') !== null ? max(0, (int) $this->option('import-limit')) : $config->importLimit();
        $only = ltrim(trim((string) $this->option('account')), '@');

        $candidates = SocialAccount::query()
            ->where('platform', Platform::LinkedInPage)
            ->get()
            ->filter(fn (SocialAccount $account): bool => $only === ''
                || $account->id === $only
                || NativeLinkedInAccounts::organizationId($account) === $only
                || strcasecmp(ltrim((string) $account->username, '@'), $only) === 0);

        foreach ($candidates as $account) {
            if (($reason = $native->reason($account)) !== null) {
                $this->warn("@{$account->username}: skipped, {$reason}.");
            }
        }

        $accounts = $candidates->filter(fn (SocialAccount $account): bool => $native->uses($account))->values();

        if ($accounts->isEmpty()) {
            $this->warn('No LinkedIn page channel is measured natively'.($only !== '' ? " for {$only}" : '').'.');

            return self::FAILURE;
        }

        if ($days > LinkedInConfig::STATS_WINDOW_DAYS) {
            $this->warn('LinkedIn keeps post statistics for 12 months: older posts are imported without metrics.');
        }

        $rows = [];
        $failed = false;

        foreach ($accounts as $account) {
            $before = $this->publicationCount($account);
            $state = $this->backfillState($account, $days, (bool) $this->option('resume'));

            if ($account->status !== AccountStatus::Connected) {
                $this->warn("@{$account->username}: the channel is not connected, so the scheduled analytics jobs skip it; reconnect it in TryPost.");
            }

            [$pages, $seen, $error] = $this->runBackfill($account, $state, $sync, $collectors, $metrics);
            $stopped = in_array($error, ['rate_limited', 'transient'], true);
            $failed = $failed || ($error !== null && ! $stopped);

            $imported = $this->importIntoSent($account, $importLimit);
            $followerDays = $this->option('skip-followers') || $error !== null
                ? 0
                : $this->writeFollowerHistory($account, $days, $api, $writer, $accountKeys);

            $state->refresh();
            $rows[] = [
                '@'.ltrim((string) $account->username, '@'),
                (string) NativeLinkedInAccounts::organizationId($account),
                $pages,
                $seen,
                $this->publicationCount($account) - $before,
                $imported,
                $followerDays,
                $state->status->value.($error !== null ? " ({$error})" : ''),
                $state->oldest_reached_at?->toDateString() ?? '-',
            ];

            if ($stopped) {
                $this->warn("@{$account->username}: stopped ({$error}: LinkedIn call budget, rate limit or no answer); the cursor is kept, run again with --resume"
                    .($error === 'rate_limited' ? ' after '.LinkedInCallBudget::nextReset()->toDateTimeString().' UTC' : '').'.');
            }
        }

        $this->table(
            ['Channel', 'Organization', 'Pages', 'Posts seen', 'New publications', 'Imported to Sent', 'Follower days', 'Backfill', 'Oldest post'],
            $rows,
        );
        $this->line("LinkedIn calls used today: {$budget->used()} of {$config->dailyCallBudget()}.");
        $this->line('First metric reads are queued on the analytics queue (batched: one LinkedIn call per 50 posts); later reads follow the usual schedule.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The account's backfill state (created like BootstrapAccountAnalytics
     * does). Restarted from the beginning with the new target, or, with
     * --resume, kept at its cursor and made runnable again.
     */
    private function backfillState(SocialAccount $account, int $days, bool $resume): AnalyticsSyncState
    {
        $state = AnalyticsSyncState::query()
            ->where('social_account_id', $account->id)
            ->forCollector(SyncCollector::PublicationBackfill)
            ->first()
            ?? AnalyticsSyncState::query()->firstOrCreate(
                [...AnalyticsSyncState::identityFor($account), 'collector' => SyncCollector::PublicationBackfill],
                ['social_account_id' => $account->id, 'status' => SyncStatus::Pending, 'checkpoint' => ['cursor' => null, 'revision' => 0]],
            );

        if ($resume && filled(data_get($state->checkpoint, 'cursor')) && $state->status !== SyncStatus::Complete) {
            $state->update([
                'social_account_id' => $account->id,
                'status' => SyncStatus::Running,
                'last_error_category' => null,
            ]);
            $this->line("@{$account->username}: resuming at post ".data_get($state->checkpoint, 'cursor').'.');

            return $state;
        }

        $state->update([
            'social_account_id' => $account->id,
            'status' => SyncStatus::Pending,
            'checkpoint' => ['cursor' => null, 'revision' => (int) data_get($state->checkpoint, 'revision', 0) + 1],
            'target_since' => CarbonImmutable::now('UTC')->subDays($days)->startOfDay(),
            'last_error_category' => null,
        ]);

        return $state;
    }

    /**
     * @return array{int, int, ?string} pages read, posts seen, error category
     */
    private function runBackfill(
        SocialAccount $account,
        AnalyticsSyncState $state,
        AdvanceAnalyticsSyncState $sync,
        PublicationHistoryCollectorFactory $collectors,
        QueuePublicationMetricsForPage $metrics,
    ): array {
        $pages = 0;
        $seen = 0;
        $waits = 0;

        while ($pages < self::MAX_PAGES) {
            $capture = $sync->begin($state->id, socialAccountId: $account->id);

            if ($capture === null) {
                break;
            }

            try {
                $page = $collectors->for($account)->page($account, $capture['cursor'], $capture['cutoff']);
            } catch (AnalyticsCollectionException $exception) {
                $seconds = $exception->retryAt
                    ? (int) ceil(CarbonImmutable::now('UTC')->diffInSeconds($exception->retryAt, false))
                    : null;

                // A short limit is waited out; a long one (budget, daily
                // limit) keeps the cursor for --resume instead of sleeping.
                if ($exception->category === 'rate_limited' && $seconds !== null && $seconds <= self::SHORT_WAIT_SECONDS && $waits < self::MAX_SHORT_WAITS) {
                    $waits++;
                    $sync->recordFailure($state->id, $capture['revision'], 'rate_limited', false, $account->id);
                    $this->line('LinkedIn rate limit reached, waiting '.max(1, $seconds).'s.');
                    sleep(max(1, $seconds));

                    continue;
                }

                $resumable = in_array($exception->category, ['rate_limited', 'transient'], true);
                $sync->recordFailure($state->id, $capture['revision'], $exception->category, ! $resumable, $account->id);

                return [$pages, $seen, $exception->category];
            } catch (ConnectionException) {
                $sync->recordFailure($state->id, $capture['revision'], 'transient', false, $account->id);

                return [$pages, $seen, 'transient'];
            }

            $pages++;
            $seen += count($page->publications);
            $result = $sync->handle($state->id, $capture['revision'], $account, $page);
            $metrics->handle($account, $page);

            if ($result['terminal'] || ! $result['advanced']) {
                break;
            }
        }

        return [$pages, $seen, null];
    }

    /**
     * ImportExternalPosts with the module's (higher) limit for this run only:
     * the scheduled pipeline keeps EXTERNAL_POSTS_IMPORT_LIMIT.
     */
    private function importIntoSent(SocialAccount $account, int $limit): int
    {
        $previous = config('trypost.external_posts.import_limit');
        config(['trypost.external_posts.import_limit' => $limit]);

        try {
            $postIds = ImportExternalPosts::execute($account);
        } finally {
            config(['trypost.external_posts.import_limit' => $previous]);
        }

        foreach ($postIds as $postId) {
            ImportExternalPostMedia::dispatch($postId);
        }

        return count($postIds);
    }

    /**
     * The daily follower count of the days TryPost has no actual reading of,
     * derived backwards from today's count and LinkedIn's daily gains:
     * count at the end of day D = today's count - gains after D. LinkedIn
     * reports gains up to two days ago, so the last days are estimated with
     * no change; every derived day is stored as `estimated`.
     */
    private function writeFollowerHistory(
        SocialAccount $account,
        int $days,
        LinkedInApi $api,
        WriteAccountDailySnapshot $writer,
        ResolveAnalyticsAccountKey $accountKeys,
    ): int {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $days = min($days, LinkedInConfig::STATS_WINDOW_DAYS);

        try {
            $current = $api->followerCount($account);
            $gains = $api->followerGains($account, $today->subDays($days), $today);
        } catch (AnalyticsCollectionException|ConnectionException $exception) {
            $this->warn('Follower history not read from LinkedIn: '.$exception->getMessage());

            return 0;
        }

        $actual = AnalyticsAccountDailySnapshot::query()
            ->where('workspace_id', $account->workspace_id)
            ->where('social_account_key', $accountKeys->for($account))
            ->where('provenance', ObservationProvenance::Actual)
            ->pluck('date')
            ->map(fn (mixed $date): string => CarbonImmutable::parse($date)->toDateString())
            ->flip();
        $written = 0;
        $followers = $current;

        for ($day = $today->subDay(); $day->greaterThanOrEqualTo($today->subDays($days)); $day = $day->subDay()) {
            // $followers is the count at the end of $day: today's count minus
            // the gains of the days after it.
            $followers -= $gains[$day->addDay()->toDateString()] ?? 0;
            $date = $day->toDateString();

            if ($actual->has($date) || $followers < 0) {
                continue;
            }

            $writer->handle($account, new AccountDailyObservation(
                date: $day,
                followers: $followers,
                provenance: ObservationProvenance::Actual,
                precision: MetricPrecision::Estimated,
                providerObservedAt: $day->endOfDay(),
                collectedAt: CarbonImmutable::now('UTC'),
            ));
            $written++;
        }

        return $written;
    }

    private function publicationCount(SocialAccount $account): int
    {
        return AnalyticsPublication::query()->where('social_account_id', $account->id)->count();
    }
}
