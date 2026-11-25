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
 * A found organization is cached for 30 days for everyone (the URN is the
 * same whichever token found it). Any failure (not found, 401, 403, 429,
 * server error, no answer, call budget used up) is cached for 15 minutes for
 * the channel whose token failed only (its social account id, else a sha256
 * of the token; never the token itself): one channel's token lacking the
 * permission must not turn another, authorized channel's mentions into plain
 * text. So a post's retries and the next posts do not call again. Each call
 * takes a unit of the module's daily LinkedIn call budget.
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
     *
     * @param  string|null  $accountId  the publishing channel (social account id), which keys a cached failure
     */
    public function resolve(string $vanity, string $token, ?string $accountId = null): OrganizationLookup
    {
        $vanity = CompanyMentions::vanity($vanity);
        $found = $this->cached($vanity);

        if ($found !== null) {
            return $found;
        }

        $missed = Cache::get($this->missKey($vanity, $token, $accountId));

        if (is_array($missed)) {
            return OrganizationLookup::failed((string) ($missed['reason'] ?? 'not resolved (cached)'), is_int($missed['status'] ?? null) ? $missed['status'] : null);
        }

        $lookup = $this->lookup($vanity, $token);
        $this->remember($vanity, $lookup, $token, $accountId);

        return $lookup;
    }

    /**
     * The organization a lookup already found (any channel's), without a call.
     */
    public function cached(string $vanity): ?OrganizationLookup
    {
        $cached = Cache::get($this->foundKey(CompanyMentions::vanity($vanity)));

        // Only a found organization is shared; an older failure stored here is ignored.
        return is_array($cached) && is_string($cached['id'] ?? null)
            ? OrganizationLookup::found($cached['id'], is_string($cached['name'] ?? null) ? $cached['name'] : null)
            : null;
    }

    /**
     * Caches a lookup's answer: found for 30 days for every channel, a
     * failure for 15 minutes for this channel only.
     */
    public function remember(string $vanity, OrganizationLookup $lookup, string $token = '', ?string $accountId = null): void
    {
        $vanity = CompanyMentions::vanity($vanity);

        if ($lookup->resolved()) {
            Cache::put($this->foundKey($vanity), ['id' => $lookup->id, 'name' => $lookup->name], now()->addDays(self::FOUND_CACHE_DAYS));
            Cache::forget($this->missKey($vanity, $token, $accountId));
        } else {
            Cache::put($this->missKey($vanity, $token, $accountId), ['reason' => $lookup->reason, 'status' => $lookup->status], now()->addMinutes(self::MISS_CACHE_MINUTES));
        }
    }

    private function foundKey(string $vanity): string
    {
        return self::CACHE_PREFIX.sha1($vanity);
    }

    /**
     * Per channel: the social account id when known, else a sha256 of the
     * token (the token itself is never stored).
     */
    private function missKey(string $vanity, string $token, ?string $accountId): string
    {
        $channel = filled($accountId) ? 'account:'.$accountId : 'token:'.hash('sha256', $token);

        return self::CACHE_PREFIX.'miss:'.sha1($vanity).':'.sha1($channel);
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
