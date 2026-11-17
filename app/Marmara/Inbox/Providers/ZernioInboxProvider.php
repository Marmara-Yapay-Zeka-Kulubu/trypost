<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Providers;

use App\Enums\SocialAccount\Platform;
use App\Marmara\Inbox\Exceptions\InboxProviderException;
use App\Marmara\Inbox\InboxConfig;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Zernio\ZernioConfig;
use App\Models\SocialAccount;
use App\Support\Analytics\RetryAfter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Zernio's unified inbox for the channels mapped to a Zernio account
 * (TikTok, and any channel in MARMARA_INBOX_ZERNIO_ACCOUNTS): comments
 * (`/v1/inbox/comments`) and DMs (`/v1/inbox/conversations`). Off by default
 * (MARMARA_INBOX_ZERNIO): a legacy Zernio plan answers 403 INBOX_REQUIRED,
 * and sent DMs count toward Zernio's outbound allowance.
 *
 * @see https://docs.zernio.com/products/comments
 * @see https://docs.zernio.com/products/messaging
 * @see https://docs.zernio.com/messages/list-inbox-conversations
 */
class ZernioInboxProvider implements InboxProvider
{
    /** Zernio's platform names whose DMs the inbox API serves. */
    private const array DM_PLATFORMS = ['instagram', 'facebook', 'twitter', 'tiktok', 'telegram', 'bluesky', 'reddit', 'whatsapp'];

    /** Zernio polls comments of these (X answers platform_not_supported). */
    private const array COMMENT_PLATFORMS = ['instagram', 'facebook', 'threads', 'tiktok', 'youtube', 'linkedin', 'reddit', 'bluesky'];

    /** Platforms Zernio can hide comments on. */
    private const array HIDE_PLATFORMS = ['instagram', 'facebook', 'threads', 'tiktok'];

    /** Reply length per network (characters). */
    private const array COMMENT_LIMITS = [
        'tiktok' => 150, 'instagram' => 2200, 'facebook' => 8000, 'linkedin' => 1250, 'twitter' => 280,
        'threads' => 500, 'youtube' => 10000, 'bluesky' => 300, 'reddit' => 10000,
    ];

    private const array MESSAGE_LIMITS = [
        'instagram' => 1000, 'facebook' => 2000, 'tiktok' => 1000, 'twitter' => 10000, 'telegram' => 4096,
        'bluesky' => 1000, 'reddit' => 10000, 'whatsapp' => 4096,
    ];

    /** Hours a reply stays possible after the person's last message. */
    private const array MESSAGE_WINDOWS = ['instagram' => 24, 'facebook' => 24, 'tiktok' => 48, 'whatsapp' => 24];

    public function __construct(
        private readonly InboxConfig $config,
        private readonly ZernioConfig $zernio,
    ) {}

    public function key(): string
    {
        return 'zernio';
    }

    public function enabled(): bool
    {
        return $this->config->providerEnabled('zernio');
    }

    public function handles(SocialAccount $account): bool
    {
        return $this->zernioAccountId($account) !== null;
    }

    /**
     * Whether the channel is listed in MARMARA_INBOX_ZERNIO_ACCOUNTS (Zernio
     * then wins over a native provider).
     */
    public function explicitlyMapped(SocialAccount $account): bool
    {
        return $this->match($account, (array) data_get($this->config->zernioAccounts(), $account->platform->value, [])) !== null;
    }

    public function zernioAccountId(SocialAccount $account): ?string
    {
        return $this->match($account, (array) data_get($this->config->zernioAccounts(), $account->platform->value, []))
            ?? $this->zernio->zernioAccountFor($account);
    }

    public function capabilities(SocialAccount $account): array
    {
        $platform = self::platformName($account->platform);
        $capabilities = [];

        if (in_array($platform, self::COMMENT_PLATFORMS, true)) {
            $capabilities[InboxItem::KIND_COMMENT] = ['see' => true, 'reply' => true, 'hide' => in_array($platform, self::HIDE_PLATFORMS, true)];
        }

        if (in_array($platform, self::DM_PLATFORMS, true)) {
            $capabilities[InboxItem::KIND_MESSAGE] = ['see' => true, 'reply' => true, 'hide' => false];
        }

        return $capabilities;
    }

    public function missingPermission(SocialAccount $account, string $kind): ?string
    {
        if ($this->zernio->apiKey() === null) {
            return 'ZERNIO_API_KEY';
        }

        return array_key_exists($kind, $this->capabilities($account)) ? null : 'a network Zernio serves for this kind';
    }

    public function missingReplyPermission(SocialAccount $account, string $kind): ?string
    {
        return $this->missingPermission($account, $kind);
    }

    public function fetch(SocialAccount $account, string $kind, array $cursor): FetchResult
    {
        $accountId = $this->zernioAccountId($account) ?? throw InboxProviderException::unsupported('the channel is not mapped to a Zernio account');

        return $kind === InboxItem::KIND_MESSAGE
            ? $this->fetchMessages($account, $accountId, $cursor)
            : $this->fetchComments($account, $accountId, $cursor);
    }

    public function reply(InboxItem $item, string $text, string $idempotencyKey): string
    {
        $accountId = (string) (data_get($item->meta, 'zernio_account_id') ?? ($item->socialAccount ? $this->zernioAccountId($item->socialAccount) : null));

        if ($item->kind === InboxItem::KIND_MESSAGE) {
            $answer = $this->send(fn (PendingRequest $http): Response => $http
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('/inbox/conversations/'.rawurlencode((string) $item->thread_id).'/messages', [
                    'accountId' => $accountId,
                    'message' => $text,
                ]), 'message reply');

            return (string) (data_get($answer, 'data.messageId') ?? data_get($answer, 'data.messageIds.0'));
        }

        $answer = $this->send(fn (PendingRequest $http): Response => $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/inbox/comments/'.rawurlencode((string) $item->post_external_id), [
                'accountId' => $accountId,
                'message' => $text,
                'commentId' => $item->external_id,
            ]), 'comment reply');

        return (string) data_get($answer, 'data.commentId');
    }

    public function hide(InboxItem $item): void
    {
        $this->send(fn (PendingRequest $http): Response => $http->post(
            '/inbox/comments/'.rawurlencode((string) $item->post_external_id).'/'.rawurlencode($item->external_id).'/hide',
            ['accountId' => (string) data_get($item->meta, 'zernio_account_id')],
        ), 'hide comment');
    }

    public function replyTextProblem(InboxItem $item, string $text): ?string
    {
        $platform = (string) data_get($item->meta, 'zernio_platform', $item->platform);
        $limits = $item->kind === InboxItem::KIND_MESSAGE ? self::MESSAGE_LIMITS : self::COMMENT_LIMITS;
        $max = $limits[$platform] ?? null;

        return $max !== null && mb_strlen($text) > $max ? "{$platform} takes up to {$max} characters here" : null;
    }

    /**
     * Cursor: the posts with comments of the window (listed again every 15
     * minutes) and which of them the next run reads.
     *
     * @param  array<string, mixed>  $cursor
     */
    private function fetchComments(SocialAccount $account, string $accountId, array $cursor): FetchResult
    {
        $listedAt = data_get($cursor, 'listed_at');
        $posts = array_values(array_filter((array) data_get($cursor, 'posts', []), 'is_array'));

        if (! is_string($listedAt) || CarbonImmutable::parse($listedAt)->lessThan(CarbonImmutable::now('UTC')->subMinutes(15))) {
            $answer = $this->send(fn (PendingRequest $http): Response => $http->get('/inbox/comments', [
                'accountId' => $accountId,
                'sortBy' => 'date',
                'sortOrder' => 'desc',
                'since' => CarbonImmutable::now('UTC')->subDays($this->config->postWindowDays())->toIso8601String(),
                'minComments' => 1,
                'view' => 'summary',
                'limit' => 25,
            ]), 'posts with comments');
            $this->assertAccountServed($answer, $accountId);
            $posts = collect((array) data_get($answer, 'data', []))
                ->filter(fn (mixed $post): bool => is_string(data_get($post, 'id')))
                ->map(fn (mixed $post): array => [
                    'id' => (string) data_get($post, 'id'),
                    'text' => is_string(data_get($post, 'content')) ? (string) data_get($post, 'content') : null,
                    'permalink' => is_string(data_get($post, 'permalink')) ? (string) data_get($post, 'permalink') : null,
                ])
                ->values()
                ->all();
            $listedAt = CarbonImmutable::now('UTC')->toIso8601String();
        }

        if ($posts === []) {
            return new FetchResult([], ['posts' => [], 'listed_at' => $listedAt, 'next' => 0]);
        }

        $start = ((int) data_get($cursor, 'next', 0)) % count($posts);
        $take = min($this->config->postsPerRun('zernio'), count($posts));
        $items = [];

        for ($offset = 0; $offset < $take; $offset++) {
            $post = $posts[($start + $offset) % count($posts)];
            $answer = $this->send(fn (PendingRequest $http): Response => $http->get('/inbox/comments/'.rawurlencode($post['id']), [
                'accountId' => $accountId,
                'limit' => 50,
            ]), 'comments');

            foreach ((array) data_get($answer, 'comments', []) as $comment) {
                array_push($items, ...$this->mapComment($account, $accountId, (array) $comment, $post, null));
            }
        }

        return new FetchResult($items, ['posts' => $posts, 'listed_at' => $listedAt, 'next' => ($start + $take) % count($posts)]);
    }

    /**
     * @param  array<string, mixed>  $comment
     * @param  array{id: string, text: string|null, permalink: string|null}  $post
     * @return list<InboxItemData>
     */
    private function mapComment(SocialAccount $account, string $accountId, array $comment, array $post, ?string $parent): array
    {
        $id = data_get($comment, 'id');
        $created = data_get($comment, 'createdTime');

        if (! is_string($id) || ! is_string($created)) {
            return [];
        }

        $parent = $parent ?? (is_string(data_get($comment, 'parentId')) ? (string) data_get($comment, 'parentId') : null);
        $platform = self::platformName($account->platform);
        $text = data_get($comment, 'message');
        $name = data_get($comment, 'from.name');
        $username = data_get($comment, 'from.username');
        $picture = data_get($comment, 'from.picture');
        $url = data_get($comment, 'url');

        $items = [new InboxItemData(
            kind: InboxItem::KIND_COMMENT,
            externalId: $id,
            receivedAt: CarbonImmutable::parse($created)->utc(),
            text: is_string($text) ? $text : null,
            threadId: $parent ?? $id,
            parentExternalId: $parent,
            postExternalId: $post['id'],
            postExcerpt: $post['text'] !== null ? mb_substr($post['text'], 0, 280) : null,
            authorId: is_scalar(data_get($comment, 'from.id')) ? (string) data_get($comment, 'from.id') : null,
            authorName: is_string($name) ? $name : (is_string($username) ? $username : null),
            authorHandle: is_string($username) ? $username : null,
            authorAvatarUrl: is_string($picture) ? $picture : null,
            permalink: is_string($url) ? $url : $post['permalink'],
            canReply: (bool) data_get($comment, 'canReply', true),
            fromSelf: (bool) data_get($comment, 'from.isOwner', false),
            hidden: (bool) data_get($comment, 'isHidden', false),
            meta: ['zernio_account_id' => $accountId, 'zernio_platform' => $platform],
        )];

        foreach ((array) data_get($comment, 'replies', []) as $reply) {
            array_push($items, ...$this->mapComment($account, $accountId, (array) $reply, $post, $id));
        }

        return $items;
    }

    /**
     * Conversations updated since the cursor (newest first), then their
     * latest incoming messages.
     *
     * @param  array<string, mixed>  $cursor
     */
    private function fetchMessages(SocialAccount $account, string $accountId, array $cursor): FetchResult
    {
        $since = is_string(data_get($cursor, 'since')) ? CarbonImmutable::parse((string) data_get($cursor, 'since')) : null;
        $answer = $this->send(fn (PendingRequest $http): Response => $http->get('/inbox/conversations', [
            'accountId' => $accountId,
            'sortOrder' => 'desc',
            'limit' => 25,
        ]), 'conversations');
        $this->assertAccountServed($answer, $accountId);
        $platform = self::platformName($account->platform);
        $window = self::MESSAGE_WINDOWS[$platform] ?? null;
        $newest = $since;
        $items = [];

        foreach ((array) data_get($answer, 'data', []) as $conversation) {
            $conversationId = data_get($conversation, 'id');
            $updated = is_string(data_get($conversation, 'updatedTime')) ? CarbonImmutable::parse((string) data_get($conversation, 'updatedTime'))->utc() : null;

            if (! is_string($conversationId) || ($updated !== null && $since !== null && $updated->lessThanOrEqualTo($since))) {
                continue;
            }

            if ($updated !== null && ($newest === null || $updated->greaterThan($newest))) {
                $newest = $updated;
            }

            $messages = $this->send(fn (PendingRequest $http): Response => $http->get('/inbox/conversations/'.rawurlencode($conversationId).'/messages', [
                'accountId' => $accountId,
                'sortOrder' => 'desc',
                'limit' => 20,
            ]), 'messages');

            foreach ((array) data_get($messages, 'messages', []) as $message) {
                $id = data_get($message, 'id');
                $created = data_get($message, 'createdAt');

                if (! is_string($id) || ! is_string($created)) {
                    continue;
                }

                $at = CarbonImmutable::parse($created)->utc();
                $text = data_get($message, 'message');
                $senderName = data_get($message, 'senderName') ?? data_get($conversation, 'participantName');
                $picture = data_get($conversation, 'participantPicture');

                $items[] = new InboxItemData(
                    kind: InboxItem::KIND_MESSAGE,
                    externalId: $id,
                    receivedAt: $at,
                    text: is_string($text) ? $text : null,
                    threadId: $conversationId,
                    authorId: is_scalar(data_get($message, 'senderId')) ? (string) data_get($message, 'senderId') : null,
                    authorName: is_string($senderName) ? $senderName : null,
                    authorHandle: is_string(data_get($conversation, 'participantName')) ? (string) data_get($conversation, 'participantName') : null,
                    authorAvatarUrl: is_string($picture) ? $picture : null,
                    permalink: is_string(data_get($conversation, 'url')) ? (string) data_get($conversation, 'url') : null,
                    replyWindowEndsAt: $window === null ? null : $at->addHours($window),
                    fromSelf: data_get($message, 'direction') === 'outgoing',
                    meta: ['zernio_account_id' => $accountId, 'zernio_platform' => $platform],
                );
            }
        }

        return new FetchResult($items, ['since' => $newest?->toIso8601String()]);
    }

    /**
     * A list answer skips an account it could not read and names it in
     * `meta.failedAccounts`; for our account that is the answer.
     *
     * @param  array<string, mixed>  $answer
     */
    private function assertAccountServed(array $answer, string $accountId): void
    {
        foreach ((array) data_get($answer, 'meta.failedAccounts', []) as $failed) {
            if (data_get($failed, 'accountId') !== $accountId) {
                continue;
            }

            $code = (string) data_get($failed, 'code', '');
            $error = (string) data_get($failed, 'error', 'Zernio could not read the account');

            throw $this->categorize($code, $error, null);
        }
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call, string $operation): array
    {
        $key = $this->zernio->apiKey() ?? throw InboxProviderException::needsPermission('ZERNIO_API_KEY');

        try {
            $response = $call(Http::baseUrl($this->zernio->baseUrl())
                ->withToken($key)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(30));
        } catch (ConnectionException) {
            throw new InboxProviderException('transient', "Zernio {$operation}: no answer in time");
        }

        if ($response->successful()) {
            return (array) $response->json();
        }

        $code = (string) (data_get($response->json(), 'code') ?? data_get($response->json(), 'error.code') ?? '');
        $message = data_get($response->json(), 'error');
        $message = is_string($message) ? $message : (string) data_get($response->json(), 'message', '');

        if ($response->status() === 429) {
            $seconds = data_get($response->json(), 'details.retryAfterSeconds');
            $retryAt = RetryAfter::from($response)
                ?? (is_numeric($seconds) ? CarbonImmutable::now('UTC')->addSeconds((int) $seconds) : CarbonImmutable::now('UTC')->addMinutes(15));

            throw InboxProviderException::rateLimited("Zernio {$operation}: rate limited (HTTP 429)", $retryAt);
        }

        if ($response->status() === 401) {
            throw new InboxProviderException('authentication', 'Zernio refused ZERNIO_API_KEY');
        }

        throw $this->categorize($code, "Zernio {$operation} failed with HTTP {$response->status()}".($message !== '' ? ' ('.mb_substr($message, 0, 200).')' : ''), $response);
    }

    private function categorize(string $code, string $message, ?Response $response): InboxProviderException
    {
        $code = strtoupper($code);

        return match (true) {
            $code === 'INBOX_REQUIRED' => InboxProviderException::needsPermission('the Zernio Inbox (INBOX_REQUIRED: start the trial or upgrade the Zernio plan)'),
            $code === 'MESSAGE_ALLOWANCE_EXCEEDED' => InboxProviderException::needsPermission('Zernio outbound messages (message_allowance_exceeded: add a payment method in Zernio)'),
            $code === 'PLATFORM_LIMITATION' => InboxProviderException::unsupported('Zernio cannot serve this here (PLATFORM_LIMITATION; for TikTok, connect the account through TikTok\'s Business app in Zernio)'),
            $code === 'PLATFORM_NOT_SUPPORTED' => InboxProviderException::unsupported('the network does not allow this through Zernio (platform_not_supported)'),
            $response?->status() === 403 => new InboxProviderException('needs_permission', $message),
            $response !== null && $response->serverError() => new InboxProviderException('transient', $message),
            default => new InboxProviderException('error', $message),
        };
    }

    /**
     * @param  array<string, string>  $entries  TryPost account => Zernio account
     */
    private function match(SocialAccount $account, array $entries): ?string
    {
        $candidates = array_filter([(string) $account->id, (string) $account->platform_user_id, ltrim((string) $account->username, '@')]);

        foreach ($entries as $key => $zernioAccount) {
            foreach ($candidates as $candidate) {
                if (is_string($zernioAccount) && $zernioAccount !== '' && strcasecmp(ltrim(trim((string) $key), '@'), $candidate) === 0) {
                    return $zernioAccount;
                }
            }
        }

        return null;
    }

    /**
     * The platform name Zernio uses for a TryPost platform.
     */
    public static function platformName(Platform $platform): string
    {
        return match ($platform) {
            Platform::X => 'twitter',
            Platform::InstagramFacebook => 'instagram',
            default => ZernioConfig::zernioPlatform($platform),
        };
    }
}
