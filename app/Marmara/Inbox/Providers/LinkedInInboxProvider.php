<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Providers;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Marmara\Inbox\Exceptions\InboxProviderException;
use App\Marmara\Inbox\InboxConfig;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Support\InboxAccessToken;
use App\Marmara\LinkedIn\LinkedInApi;
use App\Marmara\LinkedIn\LinkedInCallBudget;
use App\Marmara\LinkedIn\LinkedInConfig;
use App\Marmara\LinkedIn\NativeLinkedInAccounts;
use App\Marmara\Zernio\ZernioConfig;
use App\Models\SocialAccount;
use App\Support\Analytics\RetryAfter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * LinkedIn page comments through the Community Management API (Comments API,
 * `/rest/socialActions/{post}/comments`). Reading needs
 * `r_organization_social_feed`, replying as the page `w_organization_social_feed`
 * (both for a page ADMINISTRATOR). LinkedIn has no API for page DMs.
 *
 * Every request takes a unit of the LinkedIn module's daily call budget
 * (LinkedInCallBudget, shared with the analytics reads, back-off after a 429
 * included) and, before that, a unit of the inbox's own share of it
 * (MARMARA_INBOX_LINKEDIN_DAILY_CALLS), so the inbox can never starve
 * analytics.
 *
 * @see https://learn.microsoft.com/linkedin/marketing/community-management/shares/comments-api
 */
class LinkedInInboxProvider implements InboxProvider
{
    public const string READ_SCOPE = 'r_organization_social_feed';

    public const string WRITE_SCOPE = 'w_organization_social_feed';

    /** LinkedIn's comment length limit (the API page does not state one; the product limit is 1,250). */
    public const int MAX_COMMENT_LENGTH = 1250;

    private const string CALLS_PREFIX = 'marmara-inbox:linkedin-calls:';

    public function __construct(
        private readonly InboxConfig $config,
        private readonly LinkedInConfig $linkedIn,
        private readonly LinkedInCallBudget $budget,
        private readonly LinkedInApi $api,
    ) {}

    public function key(): string
    {
        return 'linkedin';
    }

    public function enabled(): bool
    {
        return $this->config->providerEnabled('linkedin');
    }

    public function handles(SocialAccount $account): bool
    {
        return $account->platform === Platform::LinkedInPage
            && NativeLinkedInAccounts::organizationId($account) !== null
            && NativeLinkedInAccounts::hasNativeToken($account)
            && app(ZernioConfig::class)->zernioAccountFor($account) === null;
    }

    public function capabilities(SocialAccount $account): array
    {
        return [InboxItem::KIND_COMMENT => ['see' => true, 'reply' => true, 'hide' => false]];
    }

    public function missingPermission(SocialAccount $account, string $kind): ?string
    {
        if ($kind !== InboxItem::KIND_COMMENT) {
            return 'LinkedIn has no API for page messages';
        }

        return $account->missingScope(self::READ_SCOPE) !== null
            ? self::READ_SCOPE.' (Refresh connection on the LinkedIn page card)'
            : null;
    }

    public function missingReplyPermission(SocialAccount $account, string $kind): ?string
    {
        return $account->missingScope(self::WRITE_SCOPE) !== null
            ? self::WRITE_SCOPE.' (Refresh connection on the LinkedIn page card)'
            : null;
    }

    /**
     * Cursor: `posts` (the page's recent post URNs and when they were
     * listed) and `next` (which of them the next run starts with). Each run
     * reads the comments of `posts_per_run` posts, rotating through the
     * window, so the daily cost stays flat.
     */
    public function fetch(SocialAccount $account, string $kind, array $cursor): FetchResult
    {
        [$posts, $listedAt] = $this->recentPosts($account, $cursor);

        if ($posts === []) {
            return new FetchResult([], ['posts' => [], 'listed_at' => $listedAt, 'next' => 0]);
        }

        $start = ((int) data_get($cursor, 'next', 0)) % count($posts);
        $take = min($this->config->postsPerRun('linkedin'), count($posts));
        $organization = (string) NativeLinkedInAccounts::organizationUrn($account);
        $canReply = $this->missingReplyPermission($account, InboxItem::KIND_COMMENT) === null;
        $items = [];

        for ($offset = 0; $offset < $take; $offset++) {
            $post = $posts[($start + $offset) % count($posts)];
            $response = $this->request($account, fn (PendingRequest $http): Response => $http->get(
                $this->url('/rest/socialActions/'.rawurlencode((string) $post['urn']).'/comments', ['start' => '0', 'count' => '50']),
            ), 'comments');

            foreach ((array) $response->json('elements', []) as $element) {
                if (is_array($element)) {
                    $item = $this->map($element, $post, $organization, $canReply);

                    if ($item !== null) {
                        $items[] = $item;
                    }
                }
            }
        }

        return new FetchResult($items, [
            'posts' => $posts,
            'listed_at' => $listedAt,
            'next' => ($start + $take) % count($posts),
        ]);
    }

    public function reply(InboxItem $item, string $text, string $idempotencyKey): string
    {
        $account = $item->socialAccount ?? throw InboxProviderException::unsupported('the channel is gone');
        $organization = NativeLinkedInAccounts::organizationUrn($account)
            ?? throw InboxProviderException::unsupported('the LinkedIn page channel has no organization id');

        // LinkedIn nests one level: a reply to a reply joins its parent's thread.
        $parent = $item->parent_external_id ?? $item->external_id;
        $body = [
            'actor' => $organization,
            'object' => $item->post_external_id ?? $this->threadUrn($parent),
            'message' => ['text' => $text],
            'parentComment' => $parent,
        ];

        $response = $this->request($account, fn (PendingRequest $http): Response => $http->asJson()->post(
            $this->url('/rest/socialActions/'.rawurlencode($parent).'/comments'),
            $body,
        ), 'reply');

        $urn = $response->json('commentUrn');

        if (is_string($urn) && $urn !== '') {
            return $urn;
        }

        $id = $response->header('x-restli-id');
        $thread = $this->threadUrn($parent);

        return $id !== '' && $thread !== null ? "urn:li:comment:({$thread},{$id})" : ($id !== '' ? $id : $parent.':reply');
    }

    public function hide(InboxItem $item): void
    {
        throw InboxProviderException::unsupported('LinkedIn has no API to hide a comment');
    }

    public function replyTextProblem(InboxItem $item, string $text): ?string
    {
        return mb_strlen($text) > self::MAX_COMMENT_LENGTH
            ? 'LinkedIn comments take up to '.self::MAX_COMMENT_LENGTH.' characters'
            : null;
    }

    /**
     * The page's posts of the window, listed again at most every 12 hours
     * (the LinkedIn module's posts finder, from the same budget).
     *
     * @param  array<string, mixed>  $cursor
     * @return array{0: list<array{urn: string, text: string|null, at: string|null}>, 1: string|null}
     */
    private function recentPosts(SocialAccount $account, array $cursor): array
    {
        $listedAt = data_get($cursor, 'listed_at');
        $posts = array_values(array_filter((array) data_get($cursor, 'posts', []), 'is_array'));

        if (is_string($listedAt) && CarbonImmutable::parse($listedAt)->greaterThan(CarbonImmutable::now('UTC')->subHours(12))) {
            return [$posts, $listedAt];
        }

        $this->takeInboxUnit();

        try {
            $page = $this->api->posts($account, 0, 20);
        } catch (AnalyticsCollectionException $exception) {
            if ($exception->category === 'rate_limited') {
                $this->releaseInboxUnit();
            }

            throw $this->fromAnalytics($exception);
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays($this->config->postWindowDays());
        $posts = [];

        foreach ($page['elements'] as $post) {
            $urn = data_get($post, 'id');
            $time = data_get($post, 'publishedAt') ?? data_get($post, 'createdAt');

            if (! is_string($urn) || LinkedInApi::urnKind($urn) === null || ! is_numeric($time)) {
                continue;
            }

            $at = CarbonImmutable::createFromTimestampMsUTC((int) $time);

            if ($at->lessThan($cutoff)) {
                continue;
            }

            $text = data_get($post, 'commentary');
            $posts[] = ['urn' => $urn, 'text' => is_string($text) ? mb_substr($text, 0, 280) : null, 'at' => $at->toIso8601String()];
        }

        return [$posts, CarbonImmutable::now('UTC')->toIso8601String()];
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array{urn: string, text: string|null, at: string|null}  $post
     */
    private function map(array $element, array $post, string $organization, bool $canReply): ?InboxItemData
    {
        $id = data_get($element, 'id');
        $object = data_get($element, 'object');
        $urn = data_get($element, 'commentUrn')
            ?? (is_string($object) && is_scalar($id) ? "urn:li:comment:({$object},{$id})" : null);
        $time = data_get($element, 'created.time');

        if (! is_string($urn) || ! is_numeric($time)) {
            return null;
        }

        $actor = data_get($element, 'actor');
        $parent = data_get($element, 'parentComment');
        $text = data_get($element, 'message.text');

        return new InboxItemData(
            kind: InboxItem::KIND_COMMENT,
            externalId: $urn,
            receivedAt: CarbonImmutable::createFromTimestampMsUTC((int) $time),
            text: is_string($text) ? $text : null,
            threadId: is_string($parent) ? $parent : $urn,
            parentExternalId: is_string($parent) ? $parent : null,
            postExternalId: $post['urn'],
            postExcerpt: $post['text'],
            // The Comments API gives the author's URN only; LinkedIn does
            // not let a page look up other members' names.
            authorId: is_string($actor) ? $actor : null,
            permalink: 'https://www.linkedin.com/feed/update/'.$post['urn'].'?commentUrn='.rawurlencode($urn),
            canReply: $canReply,
            fromSelf: $actor === $organization,
        );
    }

    /**
     * The activity URN inside `urn:li:comment:(<thread>,<id>)`.
     */
    private function threadUrn(string $commentUrn): ?string
    {
        return preg_match('/^urn:li:comment:\((urn:li:[A-Za-z]+:\d+),\d+\)$/', $commentUrn, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function url(string $path, array $query = []): string
    {
        $url = $this->linkedIn->api().$path;

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /**
     * @param  callable(PendingRequest): Response  $send
     */
    private function request(SocialAccount $account, callable $send, string $operation): Response
    {
        $token = InboxAccessToken::for($account);
        $this->takeInboxUnit();

        try {
            $this->budget->take();
        } catch (AnalyticsCollectionException $exception) {
            $this->releaseInboxUnit();

            throw $this->fromAnalytics($exception);
        }

        try {
            $response = $send(Http::withToken($token)
                ->acceptJson()
                ->withHeaders([
                    'LinkedIn-Version' => $this->linkedIn->version(),
                    'X-Restli-Protocol-Version' => '2.0.0',
                ])
                ->connectTimeout($this->linkedIn->connectTimeout())
                ->timeout($this->linkedIn->timeout()));
        } catch (ConnectionException) {
            throw new InboxProviderException('transient', "LinkedIn {$operation}: no answer in time");
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();

        if ($status === 429) {
            $retryAt = RetryAfter::from($response)
                ?? CarbonImmutable::now('UTC')->addMinutes($this->linkedIn->backoffMinutes());
            $this->budget->block($retryAt);

            throw InboxProviderException::rateLimited("LinkedIn {$operation}: rate limited (HTTP 429)", $retryAt);
        }

        $detail = data_get($response->json(), 'message');
        $detail = is_string($detail) ? ' ('.mb_substr(trim($detail), 0, 200).')' : '';

        throw match (true) {
            $status === 401 => new InboxProviderException('authentication', 'LinkedIn refused the token: Refresh connection on the LinkedIn page card'),
            $status === 403 => new InboxProviderException('needs_permission', 'needs permission '.($operation === 'reply' ? self::WRITE_SCOPE : self::READ_SCOPE).' and the ADMINISTRATOR role on the page'.$detail),
            $response->serverError() => new InboxProviderException('transient', "LinkedIn {$operation} failed with HTTP {$status}"),
            default => new InboxProviderException('error', "LinkedIn {$operation} failed with HTTP {$status}{$detail}"),
        };
    }

    /**
     * One unit of the inbox's daily share of the LinkedIn budget.
     */
    private function takeInboxUnit(): void
    {
        $key = self::CALLS_PREFIX.CarbonImmutable::now('UTC')->toDateString();
        Cache::add($key, 0, now()->addDays(2));
        $used = (int) Cache::increment($key);

        if ($used > $this->config->linkedInDailyCalls()) {
            Cache::decrement($key);

            throw InboxProviderException::rateLimited('the inbox share of the LinkedIn call budget is used up for today', LinkedInCallBudget::nextReset());
        }
    }

    private function releaseInboxUnit(): void
    {
        Cache::decrement(self::CALLS_PREFIX.CarbonImmutable::now('UTC')->toDateString());
    }

    public static function inboxCallsUsedToday(): int
    {
        return (int) Cache::get(self::CALLS_PREFIX.CarbonImmutable::now('UTC')->toDateString(), 0);
    }

    private function fromAnalytics(AnalyticsCollectionException $exception): InboxProviderException
    {
        return match ($exception->category) {
            'rate_limited' => InboxProviderException::rateLimited($exception->getMessage(), $exception->retryAt),
            'authentication' => new InboxProviderException('authentication', $exception->getMessage()),
            'permission' => new InboxProviderException('needs_permission', 'needs permission r_organization_social and the ADMINISTRATOR role on the page'),
            'transient' => new InboxProviderException('transient', $exception->getMessage()),
            default => new InboxProviderException('error', $exception->getMessage()),
        };
    }
}
