<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Analytics;

use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Support\Analytics\RetryAfter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

/**
 * A Zernio analytics answer as the AnalyticsCollectionException categories
 * TryPost's analytics jobs act on: `rate_limited` and `transient` are retried
 * (a 429 at its Retry-After), `delayed` is read again later the same day,
 * `authentication` / `permission` stop until fixed.
 *
 * @see https://docs.zernio.com/analytics/get-analytics (202, 402, 424, 429 scopes)
 */
class ZernioAnalyticsResponse
{
    public static function successful(Response $response, string $operation): Response
    {
        if ($response->status() === 202) {
            throw new AnalyticsCollectionException('delayed', "Zernio {$operation}: analytics sync pending");
        }

        if ($response->successful()) {
            return $response;
        }

        $category = match (true) {
            $response->status() === 429 => 'rate_limited',
            $response->status() === 401 => 'authentication',
            in_array($response->status(), [402, 403, 412], true) => 'permission',
            // Zernio does not know the post (yet): its sync is hourly, so a
            // post published minutes ago is read again later, never marked deleted.
            $response->status() === 404 => 'delayed',
            $response->serverError() => 'transient',
            default => 'malformed',
        };

        throw new AnalyticsCollectionException(
            $category,
            "Zernio {$operation} failed with HTTP {$response->status()}",
            $category === 'rate_limited' ? self::retryAt($response) : null,
        );
    }

    /**
     * Retry-After, or the `details.retryAfterSeconds` Zernio puts in a 429 body
     * (scope `post-analytics`: the team's 30-second analytics read window).
     */
    public static function retryAt(Response $response): ?CarbonImmutable
    {
        $retryAt = RetryAfter::from($response);

        if ($retryAt !== null) {
            return $retryAt;
        }

        $seconds = data_get($response->json(), 'details.retryAfterSeconds');

        return is_numeric($seconds) && $seconds > 0
            ? CarbonImmutable::now('UTC')->addSeconds((int) ceil((float) $seconds))
            : null;
    }
}
