<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Providers\Meta;

use App\Marmara\Inbox\Exceptions\InboxProviderException;
use App\Marmara\Inbox\InboxConfig;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Providers\FetchResult;
use App\Marmara\Inbox\Providers\InboxItemData;
use App\Marmara\Inbox\Providers\InboxProvider;
use App\Marmara\Inbox\Support\InboxMessageText;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

/**
 * What Instagram and Facebook Pages share: polling the channel's recent
 * posts for comments (rotating through the window, `posts_per_run` posts a
 * run) and the Conversations API for DMs, with Meta's 24-hour messaging
 * window (the HUMAN_AGENT tag is not used: it needs its own App Review).
 */
abstract class MetaInboxProvider implements InboxProvider
{
    public const int MESSAGING_WINDOW_HOURS = 24;

    public function __construct(
        protected readonly InboxConfig $config,
        protected readonly MetaGraph $graph,
    ) {}

    public function enabled(): bool
    {
        return $this->config->providerEnabled($this->key());
    }

    public function capabilities(SocialAccount $account): array
    {
        return [
            InboxItem::KIND_COMMENT => ['see' => true, 'reply' => true, 'hide' => true],
            InboxItem::KIND_MESSAGE => ['see' => true, 'reply' => true, 'hide' => false],
        ];
    }

    /**
     * The channel's recent posts: `[id, text, permalink, at]`.
     *
     * @return list<array{id: string, text: string|null, permalink: string|null, at: string|null}>
     */
    abstract protected function listPosts(SocialAccount $account): array;

    /**
     * The comments (and replies) of one post.
     *
     * @param  array{id: string, text: string|null, permalink: string|null, at: string|null}  $post
     * @return list<InboxItemData>
     */
    abstract protected function postComments(SocialAccount $account, array $post): array;

    /** Path of the conversations edge, e.g. `{page-id}/conversations`. */
    abstract protected function conversationsPath(SocialAccount $account): string;

    /** `instagram` or `messenger`. */
    abstract protected function conversationsPlatform(): string;

    /**
     * The ids the channel itself writes with (its own comments and messages).
     *
     * @return list<string>
     */
    abstract protected function selfIds(SocialAccount $account): array;

    public function fetch(SocialAccount $account, string $kind, array $cursor): FetchResult
    {
        return $kind === InboxItem::KIND_MESSAGE
            ? $this->fetchMessages($account, $cursor)
            : $this->fetchComments($account, $cursor);
    }

    /**
     * @param  array<string, mixed>  $cursor
     */
    protected function fetchComments(SocialAccount $account, array $cursor): FetchResult
    {
        $listedAt = data_get($cursor, 'listed_at');
        $posts = array_values(array_filter((array) data_get($cursor, 'posts', []), 'is_array'));

        if (! is_string($listedAt) || CarbonImmutable::parse($listedAt)->lessThan(CarbonImmutable::now('UTC')->subHour())) {
            $cutoff = CarbonImmutable::now('UTC')->subDays($this->config->postWindowDays());
            $posts = array_values(array_filter(
                $this->listPosts($account),
                fn (array $post): bool => $post['at'] === null || CarbonImmutable::parse($post['at'])->greaterThanOrEqualTo($cutoff),
            ));
            $listedAt = CarbonImmutable::now('UTC')->toIso8601String();
        }

        if ($posts === []) {
            return new FetchResult([], ['posts' => [], 'listed_at' => $listedAt, 'next' => 0]);
        }

        $start = ((int) data_get($cursor, 'next', 0)) % count($posts);
        $take = min($this->config->postsPerRun($this->key()), count($posts));
        $items = [];

        for ($offset = 0; $offset < $take; $offset++) {
            array_push($items, ...$this->postComments($account, $posts[($start + $offset) % count($posts)]));
        }

        return new FetchResult($items, [
            'posts' => $posts,
            'listed_at' => $listedAt,
            'next' => ($start + $take) % count($posts),
        ]);
    }

    /**
     * The conversations updated since the cursor, with their 20 latest
     * messages (Meta details only the 20 most recent messages of a
     * conversation). Incoming messages only; ours are left out.
     *
     * @param  array<string, mixed>  $cursor
     */
    protected function fetchMessages(SocialAccount $account, array $cursor): FetchResult
    {
        $since = data_get($cursor, 'since');
        $since = is_string($since) ? CarbonImmutable::parse($since) : null;
        $response = $this->graph->get($account, $this->conversationsPath($account), [
            'platform' => $this->conversationsPlatform(),
            'fields' => 'id,updated_time,messages.limit(20){'.$this->messageFields().'}',
            'limit' => 25,
        ], 'conversations');

        $selfIds = $this->selfIds($account);
        $newest = $since;
        $items = [];

        foreach ((array) data_get($response, 'data', []) as $conversation) {
            $updated = data_get($conversation, 'updated_time');
            $updated = is_string($updated) ? CarbonImmutable::parse($updated)->utc() : null;

            if ($updated !== null && $since !== null && $updated->lessThanOrEqualTo($since)) {
                continue;
            }

            if ($updated !== null && ($newest === null || $updated->greaterThan($newest))) {
                $newest = $updated;
            }

            foreach ((array) data_get($conversation, 'messages.data', []) as $message) {
                $item = $this->mapMessage($account, (array) $message, (string) data_get($conversation, 'id'), $selfIds);

                if ($item !== null) {
                    $items[] = $item;
                }

                array_push($items, ...$this->mapReactions($account, (array) $message, (string) data_get($conversation, 'id'), $selfIds, $updated));
            }
        }

        return new FetchResult($items, ['since' => $newest?->toIso8601String()]);
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  list<string>  $selfIds
     */
    protected function mapMessage(SocialAccount $account, array $message, string $conversationId, array $selfIds): ?InboxItemData
    {
        $id = data_get($message, 'id');
        $created = data_get($message, 'created_time');

        if (! is_string($id) || ! is_string($created)) {
            return null;
        }

        $from = (string) data_get($message, 'from.id', '');
        $at = CarbonImmutable::parse($created)->utc();
        $rendered = InboxMessageText::graph($message);
        $username = data_get($message, 'from.username');
        $name = data_get($message, 'from.name');

        return new InboxItemData(
            kind: InboxItem::KIND_MESSAGE,
            externalId: $id,
            receivedAt: $at,
            text: $rendered['text'],
            threadId: $conversationId,
            authorId: $from !== '' ? $from : null,
            authorName: is_string($name) ? $name : (is_string($username) ? $username : null),
            authorHandle: is_string($username) ? $username : null,
            canReply: $this->missingReplyPermission($account, InboxItem::KIND_MESSAGE) === null,
            replyWindowEndsAt: $at->addHours(self::MESSAGING_WINDOW_HOURS),
            fromSelf: in_array($from, $selfIds, true),
            meta: $rendered['meta'],
        );
    }

    /**
     * The fields read for each message: text plus what makes a story
     * mention, a share or media readable.
     */
    protected function messageFields(): string
    {
        return 'id,created_time,from,to,message,attachments,shares,sticker';
    }

    /**
     * A reaction of the person (on our message or theirs) is an item of its
     * own, under the id Zernio's path gives it too. Graph has no reaction
     * time, so the conversation's last update stands in for it.
     *
     * @param  array<string, mixed>  $message
     * @param  list<string>  $selfIds
     * @return list<InboxItemData>
     */
    protected function mapReactions(SocialAccount $account, array $message, string $conversationId, array $selfIds, ?CarbonImmutable $updated): array
    {
        $id = data_get($message, 'id');
        $created = data_get($message, 'created_time');

        if (! is_string($id) || ! is_string($created)) {
            return [];
        }

        $at = $updated ?? CarbonImmutable::parse($created)->utc();
        $items = [];

        foreach ((array) data_get($message, 'reactions.data', []) as $reaction) {
            $emoji = is_string(data_get($reaction, 'reaction')) ? (string) data_get($reaction, 'reaction') : null;

            foreach ((array) data_get($reaction, 'users', []) as $user) {
                $userId = is_scalar(data_get($user, 'id')) ? (string) data_get($user, 'id') : '';

                if ($userId === '' || in_array($userId, $selfIds, true)) {
                    continue;
                }

                $username = is_string(data_get($user, 'username')) ? (string) data_get($user, 'username') : null;
                $items[] = new InboxItemData(
                    kind: InboxItem::KIND_MESSAGE,
                    externalId: InboxMessageText::reactionId($id, $emoji),
                    receivedAt: $at,
                    text: InboxMessageText::reaction($emoji),
                    threadId: $conversationId,
                    authorId: $userId,
                    authorName: $username,
                    authorHandle: $username,
                    canReply: $this->missingReplyPermission($account, InboxItem::KIND_MESSAGE) === null,
                    replyWindowEndsAt: $at->addHours(self::MESSAGING_WINDOW_HOURS),
                    meta: ['message_parts' => ['reaction'], 'reacted_message_id' => $id, 'source' => ['reaction' => $reaction]],
                );
            }
        }

        return $items;
    }

    /**
     * The raw Graph JSON of an item's message or comment, for
     * `marmara:inbox:raw`. Read only.
     *
     * @return array{request: string, match: mixed, response: array<string, mixed>}
     */
    public function rawMessage(InboxItem $item): array
    {
        $account = $this->owner($item);
        $id = (string) (data_get($item->meta, 'reacted_message_id') ?? $item->external_id);
        $fields = match (true) {
            $item->kind === InboxItem::KIND_MESSAGE => $this->messageFields(),
            $this->key() === 'instagram' => 'id,from,text,timestamp,username',
            default => 'id,from,message,created_time',
        };
        $answer = $this->graph->get($account, $id, ['fields' => $fields], 'raw message');

        return ['request' => "GET /{$id}?fields={$fields}", 'match' => $answer, 'response' => $answer];
    }

    /**
     * The id a reply answers: Meta nests replies one level deep, so a reply
     * to a reply goes to its top-level comment.
     */
    protected function rootCommentId(InboxItem $item): string
    {
        return $item->parent_external_id ?? $item->external_id;
    }

    protected function recipientId(InboxItem $item): string
    {
        return $item->author_external_id
            ?? throw InboxProviderException::unsupported('the message has no sender id to answer');
    }

    protected function owner(InboxItem $item): SocialAccount
    {
        return $item->socialAccount ?? throw InboxProviderException::unsupported('the channel is gone');
    }
}
