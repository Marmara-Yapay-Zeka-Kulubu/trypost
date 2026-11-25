<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Publishing;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Marmara\LinkedIn\Mentions\CompanyMentions;
use App\Marmara\LinkedIn\Mentions\LinkedInOrganizationResolver;
use App\Marmara\LinkedIn\Mentions\OrganizationLookup;
use App\Marmara\LinkedIn\NativeLinkedInAccounts;
use App\Marmara\Zernio\ZernioClient;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Analytics\AnalyticsAccessToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Marmara AI fork: LinkedIn company mentions for a LinkedIn page published
 * through Zernio.
 *
 * Zernio publishes `@[Name](urn:li:...)` written in `content` as a LinkedIn
 * mention, as written (https://docs.zernio.com/platforms/linkedin,
 * https://docs.zernio.com/utilities/linkedin-mentions). Zernio can also
 * resolve `@[Name](https://www.linkedin.com/company/<vanity>)` itself, but it
 * rejects the whole post when it cannot (`linkedin_mentions_invalid`), and a
 * mention must never fail a publish. So the company is resolved here and only
 * a resolved URN is sent:
 *
 * 1. a `urn:li:organization:<id>` marker is sent as is;
 * 2. a vanity already found by any channel (the resolver's 30-day cache) is
 *    sent with LinkedIn's name, without a call;
 * 3. otherwise it is looked up with a native LinkedIn token of the workspace
 *    (the post's channel, else another connected LinkedIn page, else a
 *    profile): Zernio pages often have none;
 * 4. no native token, or not resolved with it: Zernio's own resolver
 *    (`GET /accounts/{id}/linkedin-mentions?url=...`) with the Zernio account
 *    the page publishes with. A found organization goes into the same shared
 *    cache as step 3; a failure is cached for 15 minutes for that Zernio
 *    account only (hashed key);
 * 5. not resolved: the display name as plain text (one warning, no secrets).
 *
 * @see https://docs.zernio.com/linkedin-mentions/get-linkedin-mentions
 */
class ZernioCompanyMentions
{
    private const string ZERNIO_MISS_PREFIX = 'marmara-zernio:linkedin-mention:miss:';

    public function __construct(
        private readonly LinkedInOrganizationResolver $resolver,
        private readonly ZernioClient $client,
    ) {}

    /**
     * @param  string|null  $zernioAccountId  the Zernio LinkedIn account the page publishes with (step 4)
     */
    public function content(string $content, Post $post, ?string $zernioAccountId = null): string
    {
        $mentions = CompanyMentions::find($content);

        if ($mentions === []) {
            return $content;
        }

        $result = '';
        $position = 0;
        $channel = false;

        foreach ($mentions as $mention) {
            $result .= substr($content, $position, $mention['offset'] - $position);
            $position = $mention['offset'] + strlen($mention['marker']);

            if ($mention['id'] !== null) {
                $result .= $this->markup($mention['name'], $mention['id']);

                continue;
            }

            $vanity = (string) $mention['vanity'];
            $lookup = $this->resolver->cached($vanity);

            if ($lookup === null) {
                // The token is looked for once per post, only when a vanity needs it.
                $channel = $channel === false ? $this->tokenChannel($post) : $channel;
                $lookup = $channel === null
                    ? OrganizationLookup::failed('no native LinkedIn token for the Zernio page')
                    : $this->resolver->resolve($vanity, $channel['token'], $channel['account_id']);

                if (! $lookup->resolved() && filled($zernioAccountId)) {
                    $zernio = $this->resolveWithZernio($vanity, (string) $zernioAccountId);
                    $lookup = $zernio->resolved()
                        ? $zernio
                        : OrganizationLookup::failed($lookup->reason.'; Zernio: '.$zernio->reason, $zernio->status ?? $lookup->status);
                }

                if (! $lookup->resolved()) {
                    LinkedInOrganizationResolver::warn($vanity, $lookup);
                }
            }

            $result .= $lookup->resolved()
                ? $this->markup($lookup->name ?? $mention['name'], (string) $lookup->id)
                : $mention['name'];
        }

        return $result.substr($content, $position);
    }

    /**
     * Zernio documents no escaping inside `@[...]`: brackets are dropped from
     * the name so the markup stays intact.
     */
    private function markup(string $name, string $id): string
    {
        return '@['.str_replace(['[', ']'], '', $name).'](urn:li:organization:'.$id.')';
    }

    /**
     * Step 4: Zernio's resolver, behind a 15-minute miss cache per Zernio
     * account. Never throws; the API key is never logged or cached.
     */
    private function resolveWithZernio(string $vanity, string $zernioAccountId): OrganizationLookup
    {
        $vanity = CompanyMentions::vanity($vanity);
        $missKey = $this->zernioMissKey($vanity, $zernioAccountId);
        $missed = Cache::get($missKey);

        if (is_array($missed)) {
            return OrganizationLookup::failed((string) ($missed['reason'] ?? 'not resolved (cached)'), is_int($missed['status'] ?? null) ? $missed['status'] : null);
        }

        $lookup = $this->zernioLookup($vanity, $zernioAccountId);

        if ($lookup->resolved()) {
            // The same shared cache the native resolver fills and reads.
            $this->resolver->remember($vanity, $lookup);
        } else {
            Cache::put($missKey, ['reason' => $lookup->reason, 'status' => $lookup->status], now()->addMinutes(LinkedInOrganizationResolver::MISS_CACHE_MINUTES));
        }

        return $lookup;
    }

    private function zernioLookup(string $vanity, string $zernioAccountId): OrganizationLookup
    {
        if ($vanity === '') {
            return OrganizationLookup::failed('empty vanity name');
        }

        try {
            $response = $this->client->linkedInMention($zernioAccountId, 'https://www.linkedin.com/company/'.rawurlencode($vanity));
        } catch (ConnectionException) {
            return OrganizationLookup::failed('Zernio did not answer in time');
        } catch (Throwable $exception) {
            return OrganizationLookup::failed('Zernio request failed: '.class_basename($exception));
        }

        $status = $response->status();

        if (! $response->successful()) {
            $message = data_get($response->json(), 'error') ?? data_get($response->json(), 'message');

            return OrganizationLookup::failed(
                match (true) {
                    $status === 401 => 'Zernio refused the key or the account must be reconnected',
                    $status === 403 => 'Zernio refused access',
                    $status === 404 => 'not found',
                    $status === 429 => 'rate limited',
                    default => 'Zernio answered with an error',
                }.(is_string($message) && trim($message) !== '' ? ': '.mb_substr(trim($message), 0, 200) : ''),
                $status,
            );
        }

        $urn = data_get($response->json(), 'urn');
        $type = data_get($response->json(), 'type');

        if (! is_string($urn) || preg_match('/^urn:li:organization:(\d+)$/', $urn, $match) !== 1 || ($type !== null && $type !== 'organization')) {
            return OrganizationLookup::failed('Zernio did not answer an organization URN', $status);
        }

        $name = data_get($response->json(), 'displayName');

        return OrganizationLookup::found($match[1], is_string($name) && trim($name) !== '' ? trim($name) : null, $status);
    }

    /**
     * Per Zernio account, like the native per-channel misses; hashed, never
     * a raw id or key.
     */
    private function zernioMissKey(string $vanity, string $zernioAccountId): string
    {
        return self::ZERNIO_MISS_PREFIX.sha1($vanity).':'.sha1('zernio-account:'.$zernioAccountId);
    }

    /**
     * @return array{token: string, account_id: string}|null
     */
    private function tokenChannel(Post $post): ?array
    {
        $candidates = SocialAccount::query()
            ->where('workspace_id', $post->workspace_id)
            ->whereIn('platform', [Platform::LinkedInPage, Platform::LinkedIn])
            ->where('status', AccountStatus::Connected)
            ->get()
            ->filter(fn (SocialAccount $account): bool => NativeLinkedInAccounts::hasNativeToken($account))
            ->sortBy(fn (SocialAccount $account): int => match (true) {
                $account->id === $post->social_account_id => 0,
                $account->platform === Platform::LinkedInPage => 1,
                default => 2,
            });

        foreach ($candidates as $account) {
            try {
                $token = AnalyticsAccessToken::for($account);
            } catch (Throwable) {
                continue;
            }

            if ($token !== '') {
                return ['token' => $token, 'account_id' => (string) $account->id];
            }
        }

        return null;
    }
}
