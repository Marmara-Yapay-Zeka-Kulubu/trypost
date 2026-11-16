<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn;

/**
 * Reads config/marmara-linkedin.php with sane bounds.
 */
class LinkedInConfig
{
    /** LinkedIn's share statistics only cover a rolling 12-month window. */
    public const int STATS_WINDOW_DAYS = 365;

    public function enabled(): bool
    {
        return (bool) config('marmara-linkedin.native_analytics', false);
    }

    public function api(): string
    {
        return rtrim((string) config('marmara-linkedin.api', 'https://api.linkedin.com'), '/');
    }

    public function version(): string
    {
        $version = trim((string) config('marmara-linkedin.version', '202610'));

        return preg_match('/^\d{6}$/', $version) === 1 ? $version : '202610';
    }

    public function timeout(): int
    {
        return min(60, max(5, (int) config('marmara-linkedin.timeout', 20)));
    }

    public function connectTimeout(): int
    {
        return min(15, max(2, (int) config('marmara-linkedin.connect_timeout', 5)));
    }

    public function dailyCallBudget(): int
    {
        return max(1, (int) config('marmara-linkedin.daily_call_budget', 80));
    }

    public function backfillReserve(): int
    {
        return max(0, (int) config('marmara-linkedin.backfill_reserve', 20));
    }

    public function backoffMinutes(): int
    {
        return max(1, (int) config('marmara-linkedin.backoff_minutes', 60));
    }

    public function statsBatchSize(): int
    {
        return min(100, max(1, (int) config('marmara-linkedin.stats_batch_size', 50)));
    }

    public function cacheMinutes(): int
    {
        return max(0, (int) config('marmara-linkedin.cache_minutes', 720));
    }

    public function pageSize(): int
    {
        return min(100, max(1, (int) config('marmara-linkedin.page_size', 50)));
    }

    public function discoveryIntervalHours(): int
    {
        return max(0, (int) config('marmara-linkedin.discovery_interval_hours', 12));
    }

    public function videoViewsMaxAgeDays(): int
    {
        return max(0, (int) config('marmara-linkedin.video_views_max_age_days', 180));
    }

    public function importLimit(): int
    {
        return max(0, (int) config('marmara-linkedin.import_limit', 500));
    }
}
