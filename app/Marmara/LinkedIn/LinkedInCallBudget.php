<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn;

use App\Exceptions\Analytics\AnalyticsCollectionException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The module's LinkedIn call budget per UTC day (LinkedIn resets its daily
 * limits at midnight UTC), and the back-off after a 429. Every request the
 * module makes takes one unit first; a refused unit is `rate_limited` until
 * the next UTC day, so the jobs wait instead of calling.
 */
class LinkedInCallBudget
{
    private const string PREFIX = 'marmara-linkedin:';

    private bool $backfill = false;

    public function __construct(private readonly LinkedInConfig $config) {}

    /**
     * The backfill command stops `backfill_reserve` calls before the daily
     * budget, so the scheduled reads keep theirs.
     */
    public function forBackfill(bool $backfill = true): static
    {
        $this->backfill = $backfill;

        return $this;
    }

    public function take(): void
    {
        $blockedUntil = $this->blockedUntil();

        if ($blockedUntil !== null) {
            throw new AnalyticsCollectionException('rate_limited', 'LinkedIn rate limit back-off is active', $blockedUntil);
        }

        $key = $this->counterKey();
        Cache::add($key, 0, now()->addDays(2));
        $used = (int) Cache::increment($key);

        if ($used > $this->limit()) {
            Cache::decrement($key);

            throw new AnalyticsCollectionException(
                'rate_limited',
                $this->backfill ? 'LinkedIn backfill budget for today is used up' : 'LinkedIn daily call budget is used up',
                self::nextReset(),
            );
        }
    }

    public function used(): int
    {
        return (int) Cache::get($this->counterKey(), 0);
    }

    public function remaining(): int
    {
        return max(0, $this->limit() - $this->used());
    }

    public function block(CarbonImmutable $until): void
    {
        $until = $until->min(CarbonImmutable::now('UTC')->addDay());
        Cache::put(self::PREFIX.'backoff-until', $until->toIso8601String(), $until);
    }

    public function blockedUntil(): ?CarbonImmutable
    {
        $until = Cache::get(self::PREFIX.'backoff-until');

        if (! is_string($until)) {
            return null;
        }

        $until = CarbonImmutable::parse($until)->utc();

        return $until->isFuture() ? $until : null;
    }

    public static function nextReset(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDay()->startOfDay()->addMinutes(5);
    }

    private function limit(): int
    {
        $daily = $this->config->dailyCallBudget();

        return $this->backfill ? max(0, $daily - $this->config->backfillReserve()) : $daily;
    }

    private function counterKey(): string
    {
        return self::PREFIX.'calls:'.CarbonImmutable::now('UTC')->toDateString();
    }
}
