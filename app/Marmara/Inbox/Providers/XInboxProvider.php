<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Providers;

use App\Enums\SocialAccount\Platform;
use App\Marmara\Inbox\Exceptions\InboxProviderException;
use App\Marmara\Inbox\InboxConfig;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Support\InboxAccessToken;
use App\Models\SocialAccount;
use App\Support\Analytics\RetryAfter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * X mentions (as "comments") and DMs through TryPost's own X connection
 * (OAuth 2.0 user token). Off by default (MARMARA_INBOX_X): reading needs
 * an X API plan with read access (pay-per-use credits or a paid tier); the
 * Free tier answers 402/403. DMs need the dm.read / dm.write scopes, which
 * TryPost's connect asks for while this provider is on (then "Refresh
 * connection").
 *
 * @see https://docs.x.com/x-api/users/get-mentions
 * @see https://docs.x.com/x-api/direct-messages/get-dm-events
 * @see https://docs.x.com/x-api/direct-messages/create-dm-message-by-conversation-id
 */
class XInboxProvider implements InboxProvider
{
    public const int MAX_DM_LENGTH = 10000;

    public function __construct(private readonly InboxConfig $config) {}

    public function key(): string
    {
        return 'x';
    }

    public function enabled(): bool
    {
        return $this->config->providerEnabled('x');
    }

    public function handles(SocialAccount $account): bool
    {
        return $account->platform === Platform::X && filled($account->platform_user_id);
    }

    public function capabilities(SocialAccount $account): array
    {
        return [
            InboxItem::KIND_COMMENT => ['see' => true, 'reply' => true, 'hide' => false],
            InboxItem::KIND_MESSAGE => ['see' => true, 'reply' => true, 'hide' => false],
        ];
    }

    public function missingPermission(SocialAccount $account, string $kind): ?string
    {
        $missing = $kind === InboxItem::KIND_MESSAGE
            ? $account->missingScope('dm.read', 'users.read')
            : $account->missingScope('tweet.read', 'users.read');

        return $missing === null ? null : $missing.' (Refresh connection on the X card)';
    }

    public function missingReplyPermission(SocialAccount $account, string $kind): ?string
    {
        $missing = $kind === InboxItem::KIND_MESSAGE ? $account->missingScope('dm.write') : $account->missingScope('tweet.write');

        return $missing === null ? null : $missing.' (Refresh connection on the X card)';
    }

    /**
     * Mentions cursor: `since_id` (newest mention already read); while a
     * run pages back through new mentions, `next_token` and `newest` keep
     * its place. DMs cursor: `since` (newest event time read).
     */
    public function fetch(SocialAccount $account, string $kind, array $cursor): FetchResult
    {
        return $kind === InboxItem::KIND_MESSAGE ? $this->fetchMessages($account, $cursor) : $this->fetchMentions($account, $cursor);
    }

    public function reply(InboxItem $item, string $text, string $idempotencyKey): string
    {
        $account = $item->socialAccount ?? throw InboxProviderException::unsupported('the channel is gone');

        if ($item->kind === InboxItem::KIND_MESSAGE) {
            $answer = $this->send($account, 'post', '/dm_conversations/'.rawurlencode((string) $item->thread_id).'/messages', ['text' => $text], 'message reply');

            return (string) data_get($answer, 'data.dm_event_id');
        }

        $answer = $this->send($account, 'post', '/tweets', [
            'text' => $text,
            'reply' => ['in_reply_to_tweet_id' => $item->external_id],
        ], 'reply');

        return (string) data_get($answer, 'data.id');
    }

    public function hide(InboxItem $item): void
    {
        throw InboxProviderException::unsupported('hiding X replies is not part of this inbox');
    }

    public function replyTextProblem(InboxItem $item, string $text): ?string
    {
        $max = $item->kind === InboxItem::KIND_MESSAGE
            ? self::MAX_DM_LENGTH
            : ($item->socialAccount?->maxContentLength() ?? 280);

        return mb_strlen($text) > $max ? "X takes up to {$max} characters here" : null;
    }

    /**
     * @param  array<string, mixed>  $cursor
     */
    private function fetchMentions(SocialAccount $account, array $cursor): FetchResult
    {
        $sinceId = data_get($cursor, 'since_id');
        $nextToken = data_get($cursor, 'next_token');
        $query = array_filter([
            'max_results' => 50,
            'tweet.fields' => 'created_at,conversation_id,author_id,in_reply_to_user_id',
            'expansions' => 'author_id',
            'user.fields' => 'username,name,profile_image_url',
            'since_id' => is_string($sinceId) ? $sinceId : null,
            'start_time' => is_string($sinceId) ? null : CarbonImmutable::now('UTC')->subDays($this->config->postWindowDays())->format('Y-m-d\TH:i:s\Z'),
            'pagination_token' => is_string($nextToken) ? $nextToken : null,
        ], fn (mixed $value): bool => $value !== null);

        $answer = $this->send($account, 'get', '/users/'.rawurlencode((string) $account->platform_user_id).'/mentions', $query, 'mentions');
        $users = collect((array) data_get($answer, 'includes.users', []))->keyBy('id');
        $items = [];

        foreach ((array) data_get($answer, 'data', []) as $tweet) {
            $id = data_get($tweet, 'id');
            $created = data_get($tweet, 'created_at');

            if (! is_string($id) || ! is_string($created)) {
                continue;
            }

            $author = (string) data_get($tweet, 'author_id', '');
            $user = (array) $users->get($author, []);

            $items[] = new InboxItemData(
                kind: InboxItem::KIND_COMMENT,
                externalId: $id,
                receivedAt: CarbonImmutable::parse($created)->utc(),
                text: is_string(data_get($tweet, 'text')) ? (string) data_get($tweet, 'text') : null,
                threadId: is_string(data_get($tweet, 'conversation_id')) ? (string) data_get($tweet, 'conversation_id') : $id,
                postExternalId: is_string(data_get($tweet, 'conversation_id')) ? (string) data_get($tweet, 'conversation_id') : null,
                authorId: $author !== '' ? $author : null,
                authorName: is_string(data_get($user, 'name')) ? (string) data_get($user, 'name') : null,
                authorHandle: is_string(data_get($user, 'username')) ? (string) data_get($user, 'username') : null,
                authorAvatarUrl: is_string(data_get($user, 'profile_image_url')) ? (string) data_get($user, 'profile_image_url') : null,
                permalink: 'https://x.com/i/web/status/'.$id,
                canReply: $this->missingReplyPermission($account, InboxItem::KIND_COMMENT) === null,
                fromSelf: $author === (string) $account->platform_user_id,
            );
        }

        // The first page of a run carries the newest id; keep it until the
        // run has paged through everything newer than since_id.
        $newest = data_get($cursor, 'newest') ?? data_get($answer, 'meta.newest_id');
        $next = data_get($answer, 'meta.next_token');

        if (is_string($next) && $next !== '') {
            return new FetchResult($items, ['since_id' => $sinceId, 'next_token' => $next, 'newest' => $newest], hasMore: true);
        }

        return new FetchResult($items, ['since_id' => is_string($newest) ? $newest : $sinceId]);
    }

    /**
     * @param  array<string, mixed>  $cursor
     */
    private function fetchMessages(SocialAccount $account, array $cursor): FetchResult
    {
        $since = is_string(data_get($cursor, 'since')) ? CarbonImmutable::parse((string) data_get($cursor, 'since')) : null;
        $answer = $this->send($account, 'get', '/dm_events', [
            'event_types' => 'MessageCreate',
            'dm_event.fields' => 'id,text,created_at,sender_id,dm_conversation_id',
            'expansions' => 'sender_id',
            'user.fields' => 'username,name,profile_image_url',
            'max_results' => 50,
        ], 'messages');
        $users = collect((array) data_get($answer, 'includes.users', []))->keyBy('id');
        $newest = $since;
        $items = [];

        foreach ((array) data_get($answer, 'data', []) as $event) {
            $id = data_get($event, 'id');
            $created = data_get($event, 'created_at');

            if (! is_string($id) || ! is_string($created)) {
                continue;
            }

            $at = CarbonImmutable::parse($created)->utc();

            if ($since !== null && $at->lessThanOrEqualTo($since)) {
                continue;
            }

            $newest = $newest === null || $at->greaterThan($newest) ? $at : $newest;
            $sender = (string) data_get($event, 'sender_id', '');
            $user = (array) $users->get($sender, []);

            $items[] = new InboxItemData(
                kind: InboxItem::KIND_MESSAGE,
                externalId: $id,
                receivedAt: $at,
                text: is_string(data_get($event, 'text')) ? (string) data_get($event, 'text') : null,
                threadId: is_string(data_get($event, 'dm_conversation_id')) ? (string) data_get($event, 'dm_conversation_id') : null,
                authorId: $sender !== '' ? $sender : null,
                authorName: is_string(data_get($user, 'name')) ? (string) data_get($user, 'name') : null,
                authorHandle: is_string(data_get($user, 'username')) ? (string) data_get($user, 'username') : null,
                authorAvatarUrl: is_string(data_get($user, 'profile_image_url')) ? (string) data_get($user, 'profile_image_url') : null,
                canReply: $this->missingReplyPermission($account, InboxItem::KIND_MESSAGE) === null,
                fromSelf: $sender === (string) $account->platform_user_id,
            );
        }

        return new FetchResult($items, ['since' => $newest?->toIso8601String()]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(SocialAccount $account, string $method, string $path, array $payload, string $operation): array
    {
        $token = InboxAccessToken::for($account);
        $url = rtrim((string) config('trypost.platforms.x.api', 'https://api.x.com/2'), '/').$path;

        try {
            $request = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20);
            $response = $method === 'get' ? $request->get($url, $payload) : $request->asJson()->post($url, $payload);
        } catch (ConnectionException) {
            throw new InboxProviderException('transient', "X {$operation}: no answer in time");
        }

        if ($response->successful()) {
            return (array) $response->json();
        }

        throw $this->failure($response, $operation);
    }

    private function failure(Response $response, string $operation): InboxProviderException
    {
        $status = $response->status();
        $detail = data_get($response->json(), 'detail') ?? data_get($response->json(), 'title');
        $detail = is_string($detail) ? ' ('.mb_substr($detail, 0, 200).')' : '';

        return match (true) {
            $status === 429 => InboxProviderException::rateLimited("X {$operation}: rate limited", RetryAfter::from($response) ?? CarbonImmutable::now('UTC')->addMinutes(15)),
            $status === 401 => new InboxProviderException('authentication', 'X refused the token: Refresh connection on the X card'),
            $status === 402 => InboxProviderException::needsPermission('an X API plan with read access (pay-per-use credits)'.$detail),
            $status === 403 => new InboxProviderException('needs_permission', "needs permission for X {$operation} (scope or API plan){$detail}"),
            $response->serverError() => new InboxProviderException('transient', "X {$operation} failed with HTTP {$status}"),
            default => new InboxProviderException('error', "X {$operation} failed with HTTP {$status}{$detail}"),
        };
    }
}
