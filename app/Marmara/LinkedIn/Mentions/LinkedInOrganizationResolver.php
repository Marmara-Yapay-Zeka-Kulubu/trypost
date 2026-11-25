<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Mentions;

use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Marmara\LinkedIn\LinkedInCallBudget;
use App\Marmara\LinkedIn\LinkedInConfig;
use App\Support\Analytics\RetryAfter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Marmara AI fork: a LinkedIn company page's vanity name
 * (`linkedin.com/company/<vanity>`) to its organization id and localized
 * name, through the versioned REST API:
 *
 *     GET {api}/rest/organizations?q=vanityName&vanityName=<vanity>
 *     Authorization: Bearer <the publishing channel's token>
 *     LinkedIn-Version: MARMARA_LINKEDIN_API_VERSION
 *     X-Restli-Protocol-Version: 2.0.0
 *
 * A found organization is cached for 30 days, any failure (not found, 401,
 * 403, 429, server error, no answer, call budget used up) for 15 minutes, so
 * a post's retries and the next posts do not call again. Each call takes a
 * unit of the module's daily LinkedIn call budget.
 *
 * @see https://learn.microsoft.com/linkedin/marketing/community-management/organizations/organization-lookup-api
 */
class LinkedInOrganizationResolver
{
    public const int FOUND_CACHE_DAYS = 30;

    public const int MISS_CACHE_MINUTES = 15;

    private const string CACHE_PREFIX = 'marmara-linkedin:organization-vanity:';

    public function __construct(
        private readonly LinkedInConfig $config,
        private readonly LinkedInCallBudget $budget,
    ) {}

    /**
     * The organization for a vanity name, from the cache or one lookup.
     */
    public function resolve(string $vanity, string $token): OrganizationLookup
    {
        $vanity = CompanyMentions::vanity($vanity);
        $key = self::CACHE_PREFIX.sha1($vanity);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return is_string($cached['id'] ?? null)
                ? OrganizationLookup::found($cached['id'], is_string($cached['name'] ?? null) ? $cached['name'] : null)
                : OrganizationLookup::failed((string) ($cached['reason'] ?? 'not resolved (cached)'), is_int($cached['status'] ?? null) ? $cached['status'] : null);
        }

        $lookup = $this->lookup($vanity, $token);
        $this->remember($vanity, $lookup);

        return $lookup;
    }

    /**
     * Caches a lookup's answer: 30 days when found, 15 minutes otherwise.
     */
    public function remember(string $vanity, OrganizationLookup $lookup): void
    {
        $key = self::CACHE_PREFIX.sha1(CompanyMentions::vanity($vanity));

        if ($lookup->resolved()) {
            Cache::put($key, ['id' => $lookup->id, 'name' => $lookup->name], now()->addDays(self::FOUND_CACHE_DAYS));
        } else {
            Cache::put($key, ['reason' => $lookup->reason, 'status' => $lookup->status], now()->addMinutes(self::MISS_CACHE_MINUTES));
        }
    }

    /**
     * One lookup, without the cache (the `marmara:linkedin-org` command).
     * Never throws; the token is never logged.
     */
    public function lookup(string $vanity, string $token): OrganizationLookup
    {
        $vanity = CompanyMentions::vanity($vanity);

        if ($vanity === '' || $token === '') {
            return OrganizationLookup::failed($vanity === '' ? 'empty vanity name' : 'no LinkedIn token');
        }

        try {
            $this->budget->take();
        } catch (AnalyticsCollectionException $exception) {
            return OrganizationLookup::failed('LinkedIn call budget: '.$exception->getMessage());
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->withHeaders([
                    'LinkedIn-Version' => $this->config->version(),
                    'X-Restli-Protocol-Version' => '2.0.0',
                ])
                ->connectTimeout($this->config->connectTimeout())
                ->timeout($this->config->timeout())
                ->get($this->url($vanity));
        } catch (ConnectionException) {
            return OrganizationLookup::failed('LinkedIn did not answer in time');
        } catch (Throwable $exception) {
            return OrganizationLookup::failed('request failed: '.class_basename($exception));
        }

        $status = $response->status();

        if ($status === 429) {
            $this->budget->block(RetryAfter::from($response) ?? CarbonImmutable::now('UTC')->addMinutes($this->config->backoffMinutes()));
        }

        if (! $response->successful()) {
            $message = data_get($response->json(), 'message');

            return OrganizationLookup::failed(
                match (true) {
                    $status === 401 => 'LinkedIn refused the token',
                    $status === 403 => 'LinkedIn refused access (the app or token lacks the permission)',
                    $status === 404 => 'not found',
                    $status === 429 => 'rate limited',
                    default => 'LinkedIn answered with an error',
                }.(is_string($message) && $message !== '' ? ': '.mb_substr(trim($message), 0, 200) : ''),
                $status,
            );
        }

        $elements = array_values(array_filter((array) $response->json('elements', []), 'is_array'));
        $element = collect($elements)->first(
            fn (array $element): bool => is_string(data_get($element, 'vanityName'))
                && mb_strtolower((string) data_get($element, 'vanityName')) === $vanity,
        ) ?? ($elements[0] ?? null);
        $id = data_get($element, 'id');

        if (! is_numeric($id)) {
            return OrganizationLookup::failed('not found', $status);
        }

        $name = data_get($element, 'localizedName');

        return OrganizationLookup::found((string) (int) $id, is_string($name) && trim($name) !== '' ? trim($name) : null, $status);
    }

    public function url(string $vanity): string
    {
        return $this->config->api().'/rest/organizations?q=vanityName&vanityName='.rawurlencode(CompanyMentions::vanity($vanity));
    }

    /**
     * Logged once per unresolved mention; never the token.
     */
    public static function warn(string $vanity, OrganizationLookup $lookup): void
    {
        Log::warning('LinkedIn company mention published as plain text', [
            'vanity' => $vanity,
            'status' => $lookup->status,
            'reason' => $lookup->reason,
        ]);
    }
}
