<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Console;

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
use App\Marmara\Zernio\Analytics\ZernioTikTokAnalytics;
use App\Marmara\Zernio\ZernioConfig;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

/**
 * One-off historical import for the Zernio-mapped TikTok accounts: restarts
 * the account's publication backfill (TryPost's own sync state) from
 * `--days` ago and runs it here page by page through the normal pipeline
 * (upsert, first metric reads queued, external posts imported into Sent),
 * then fills the daily follower history Zernio holds.
 */
class BackfillZernioAnalytics extends Command
{
    private const int MAX_PAGES = 200;

    private const int MAX_RATE_LIMIT_WAITS = 5;

    protected $signature = 'marmara:zernio:analytics-backfill
        {--days=365 : How far back to import (Zernio keeps about 12 months)}
        {--account= : Only this TryPost channel (id or username)}
        {--import-limit= : Newest unlinked publications to import into Sent (default ZERNIO_ANALYTICS_IMPORT_LIMIT)}
        {--skip-followers : Do not write the daily follower history}';

    protected $description = 'Import the TikTok post history, metrics and follower history of the Zernio-mapped accounts';

    public function handle(
        ZernioConfig $config,
        AdvanceAnalyticsSyncState $sync,
        PublicationHistoryCollectorFactory $collectors,
        QueuePublicationMetricsForPage $metrics,
        ZernioTikTokAnalytics $analytics,
        WriteAccountDailySnapshot $writer,
        ResolveAnalyticsAccountKey $accountKeys,
    ): int {
        if (! $config->analyticsEnabled()) {
            $this->error('Zernio analytics are off: set ZERNIO_ENABLED=true (and ZERNIO_ANALYTICS_ENABLED is not false).');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $importLimit = $this->option('import-limit') !== null ? max(0, (int) $this->option('import-limit')) : $config->analyticsImportLimit();
        $only = ltrim(trim((string) $this->option('account')), '@');

        $accounts = SocialAccount::query()
            ->where('platform', Platform::TikTok)
            ->get()
            ->filter(fn (SocialAccount $account): bool => $config->analyticsAccountFor($account) !== null)
            ->filter(fn (SocialAccount $account): bool => $only === ''
                || $account->id === $only
                || strcasecmp(ltrim((string) $account->username, '@'), $only) === 0)
            ->values();

        if ($accounts->isEmpty()) {
            $this->warn('No TryPost TikTok channel is mapped to a Zernio account'.($only !== '' ? " for {$only}" : '').'.');

            return self::FAILURE;
        }

        if ($days > ZernioTikTokAnalytics::MAX_RANGE_DAYS) {
            $this->warn('Zernio keeps about 12 months of TikTok posts: older posts are not available.');
        }

        $rows = [];
        $failed = false;

        foreach ($accounts as $account) {
            $zernioAccountId = (string) $config->analyticsAccountFor($account);
            $before = $this->publicationCount($account);
            $state = $this->restartBackfill($account, $days);

            if ($account->status !== AccountStatus::Connected) {
                $this->warn("@{$account->username}: the TryPost channel is not connected, so the scheduled analytics jobs skip it; reconnect it to keep the metrics updating.");
            }

            [$pages, $seen, $error] = $this->runBackfill($account, $state, $sync, $collectors, $metrics);
            $failed = $failed || $error !== null;

            $imported = $this->importIntoSent($account, $importLimit);
            $followerDays = $this->option('skip-followers') || $error !== null
                ? 0
                : $this->writeFollowerHistory($account, $zernioAccountId, $days, $analytics, $writer, $accountKeys);

            $state->refresh();
            $rows[] = [
                '@'.ltrim((string) $account->username, '@'),
                $zernioAccountId,
                $pages,
                $seen,
                $this->publicationCount($account) - $before,
                $imported,
                $followerDays,
                $state->status->value.($error !== null ? " ({$error})" : ''),
                $state->oldest_reached_at?->toDateString() ?? '-',
            ];
        }

        $this->table(
            ['Channel', 'Zernio id', 'Pages', 'Posts seen', 'New publications', 'Imported to Sent', 'Follower days', 'Backfill', 'Oldest post'],
            $rows,
        );
        $this->line('First metric reads are queued on the analytics queue; later reads follow the usual schedule.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The account's backfill state (created like BootstrapAccountAnalytics
     * does), set back to pending from the start with the new target.
     */
    private function restartBackfill(SocialAccount $account, int $days): AnalyticsSyncState
    {
        $state = AnalyticsSyncState::query()
            ->where('social_account_id', $account->id)
            ->forCollector(SyncCollector::PublicationBackfill)
            ->first()
            ?? AnalyticsSyncState::query()->firstOrCreate(
                [...AnalyticsSyncState::identityFor($account), 'collector' => SyncCollector::PublicationBackfill],
                ['social_account_id' => $account->id, 'status' => SyncStatus::Pending, 'checkpoint' => ['cursor' => null, 'revision' => 0]],
            );

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
                if ($exception->category === 'rate_limited' && $waits < self::MAX_RATE_LIMIT_WAITS) {
                    $waits++;
                    $sync->recordFailure($state->id, $capture['revision'], 'rate_limited', false, $account->id);
                    $seconds = $exception->retryAt
                        ? max(1, (int) ceil(CarbonImmutable::now('UTC')->diffInSeconds($exception->retryAt, false)))
                        : 30;
                    $this->line("Zernio rate limit reached, waiting {$seconds}s.");
                    sleep(min(120, $seconds));

                    continue;
                }

                $sync->recordFailure($state->id, $capture['revision'], $exception->category, true, $account->id);

                return [$pages, $seen, $exception->category];
            } catch (ConnectionException) {
                $sync->recordFailure($state->id, $capture['revision'], 'transient', true, $account->id);

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
     * Zernio's daily follower counts for days TryPost has no actual reading of.
     */
    private function writeFollowerHistory(
        SocialAccount $account,
        string $zernioAccountId,
        int $days,
        ZernioTikTokAnalytics $analytics,
        WriteAccountDailySnapshot $writer,
        ResolveAnalyticsAccountKey $accountKeys,
    ): int {
        $today = CarbonImmutable::now('UTC')->startOfDay();

        try {
            $series = $analytics->followers($zernioAccountId, $today->subDays($days), $today)['series'];
        } catch (AnalyticsCollectionException|ConnectionException $exception) {
            $this->warn('Follower history not read from Zernio: '.$exception->getMessage());

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

        foreach ($series as $date => $followers) {
            if ($actual->has($date) || $date > $today->toDateString()) {
                continue;
            }

            $day = CarbonImmutable::parse($date, 'UTC');
            $writer->handle($account, new AccountDailyObservation(
                date: $day,
                followers: $followers,
                provenance: ObservationProvenance::Actual,
                precision: MetricPrecision::Exact,
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
