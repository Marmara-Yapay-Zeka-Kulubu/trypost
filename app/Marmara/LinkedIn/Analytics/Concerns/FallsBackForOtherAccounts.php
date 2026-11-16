<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Analytics\Concerns;

use App\Exceptions\Analytics\AnalyticsCollectionException;
use Closure;

/**
 * A native LinkedIn page collector handles the channels measured natively
 * and hands every other LinkedIn page channel to the collector the factory
 * would have used without this module (Zernio's, or upstream's
 * "unsupported").
 */
trait FallsBackForOtherAccounts
{
    /** @var (Closure(): object)|null */
    private ?Closure $fallback = null;

    /**
     * @param  Closure(): object  $fallback  resolves the previous collector (may throw unsupported)
     */
    public function withFallback(Closure $fallback): static
    {
        $collector = clone $this;
        $collector->fallback = $fallback;

        return $collector;
    }

    private function previous(string $reason): object
    {
        if ($this->fallback === null) {
            throw AnalyticsCollectionException::unsupported("LinkedIn page analytics: {$reason}");
        }

        return ($this->fallback)();
    }
}
