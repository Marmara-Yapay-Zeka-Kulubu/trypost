<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Providers\Meta;

use App\Enums\SocialAccount\Platform;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Providers\InboxItemData;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

/**
 * Facebook Page comments and Messenger DMs with the Page token TryPost
 * stores. Reading comments: pages_read_engagement + pages_read_user_content;
 * replying and hiding: pages_manage_engagement; Messenger: pages_messaging.
 * The member who connected the Page needs its MODERATE and MESSAGING tasks.
 * Standard Access covers Pages whose people have a role on the Meta app;
 * other Pages need Advanced Access (App Review).
 *
 * @see https://developers.facebook.com/docs/graph-api/reference/object/comments/
 * @see https://developers.facebook.com/docs/messenger-platform/conversations
 * @see https://developers.facebook.com/docs/messenger-platform/send-messages
 */
class FacebookInboxProvider extends MetaInboxProvider
{
    public const int MAX_COMMENT_LENGTH = 8000;

    /** Messenger's text limit. */
    public const int MAX_MESSAGE_LENGTH = 2000;

    public function key(): string
    {
        return 'facebook';
    }

    public function handles(SocialAccount $account): bool
    {
        return $account->platform === Platform::Facebook && filled($account->platform_user_id);
    }

    public function missingPermission(SocialAccount $account, string $kind): ?string
    {
        $missing = match ($kind) {
            InboxItem::KIND_COMMENT => $account->missingScope('pages_read_engagement', 'pages_read_user_content'),
            InboxItem::KIND_MESSAGE => $account->missingScope('pages_messaging'),
            default => 'unknown kind',
        };

        return $missing === null ? null : $missing.' (Refresh connection; requires Meta App Review advanced access for Pages without a role on the app)';
    }

    public function missingReplyPermission(SocialAccount $account, string $kind): ?string
    {
        $missing = $kind === InboxItem::KIND_MESSAGE
            ? $account->missingScope('pages_messaging')
            : $account->missingScope('pages_manage_engagement');

        return $missing === null ? null : $missing.' (Refresh connection; requires Meta App Review advanced access for Pages without a role on the app)';
    }

    public function reply(InboxItem $item, string $text, string $idempotencyKey): string
    {
        $account = $this->owner($item);

        if ($item->kind === InboxItem::KIND_COMMENT) {
            return (string) data_get($this->graph->post($account, $this->rootCommentId($item).'/comments', ['message' => $text], 'comment reply'), 'id');
        }

        $answer = $this->graph->post($account, $this->pageId($account).'/messages', [
            'recipient' => ['id' => $this->recipientId($item)],
            'messaging_type' => 'RESPONSE',
            'message' => ['text' => $text],
        ], 'message reply');

        return (string) (data_get($answer, 'message_id') ?? data_get($answer, 'id'));
    }

    public function hide(InboxItem $item): void
    {
        $this->graph->post($this->owner($item), $item->external_id, ['is_hidden' => true], 'hide comment');
    }

    public function replyTextProblem(InboxItem $item, string $text): ?string
    {
        $max = $item->kind === InboxItem::KIND_MESSAGE ? self::MAX_MESSAGE_LENGTH : self::MAX_COMMENT_LENGTH;

        return mb_strlen($text) > $max
            ? ($item->kind === InboxItem::KIND_MESSAGE ? 'Messenger messages' : 'Facebook comments')." take up to {$max} characters"
            : null;
    }

    protected function listPosts(SocialAccount $account): array
    {
        $answer = $this->graph->get($account, $this->pageId($account).'/published_posts', [
            'fields' => 'id,message,permalink_url,created_time',
            'limit' => 25,
        ], 'posts');

        return collect((array) data_get($answer, 'data', []))
            ->filter(fn (mixed $post): bool => is_string(data_get($post, 'id')))
            ->map(fn (mixed $post): array => [
                'id' => (string) data_get($post, 'id'),
                'text' => is_string(data_get($post, 'message')) ? mb_substr((string) data_get($post, 'message'), 0, 280) : null,
                'permalink' => is_string(data_get($post, 'permalink_url')) ? (string) data_get($post, 'permalink_url') : null,
                'at' => is_string(data_get($post, 'created_time')) ? (string) data_get($post, 'created_time') : null,
            ])
            ->values()
            ->all();
    }

    protected function postComments(SocialAccount $account, array $post): array
    {
        $answer = $this->graph->get($account, $post['id'].'/comments', [
            'filter' => 'stream',
            'fields' => 'id,message,from{id,name,picture},created_time,permalink_url,parent{id},is_hidden',
            'limit' => 100,
        ], 'comments');
        $selfIds = $this->selfIds($account);
        $canReply = $this->missingReplyPermission($account, InboxItem::KIND_COMMENT) === null;
        $items = [];

        foreach ((array) data_get($answer, 'data', []) as $comment) {
            $id = data_get($comment, 'id');
            $created = data_get($comment, 'created_time');

            if (! is_string($id) || ! is_string($created)) {
                continue;
            }

            $from = (string) data_get($comment, 'from.id', '');
            $parent = data_get($comment, 'parent.id');
            $parent = is_string($parent) ? $parent : null;
            $name = data_get($comment, 'from.name');
            $avatar = data_get($comment, 'from.picture.data.url');
            $text = data_get($comment, 'message');
            $permalink = data_get($comment, 'permalink_url');

            $items[] = new InboxItemData(
                kind: InboxItem::KIND_COMMENT,
                externalId: $id,
                receivedAt: CarbonImmutable::parse($created)->utc(),
                text: is_string($text) ? $text : null,
                threadId: $parent ?? $id,
                parentExternalId: $parent,
                postExternalId: $post['id'],
                postExcerpt: $post['text'],
                // Without pages_read_user_content (or for people who did not
                // allow it) Meta leaves `from` out.
                authorId: $from !== '' ? $from : null,
                authorName: is_string($name) ? $name : null,
                authorAvatarUrl: is_string($avatar) ? $avatar : null,
                permalink: is_string($permalink) ? $permalink : $post['permalink'],
                canReply: $canReply,
                fromSelf: $from !== '' && in_array($from, $selfIds, true),
                hidden: (bool) data_get($comment, 'is_hidden', false),
            );
        }

        return $items;
    }

    protected function conversationsPath(SocialAccount $account): string
    {
        return $this->pageId($account).'/conversations';
    }

    protected function conversationsPlatform(): string
    {
        return 'messenger';
    }

    protected function selfIds(SocialAccount $account): array
    {
        return array_values(array_unique(array_filter([(string) $account->platform_user_id, $this->pageId($account)])));
    }

    private function pageId(SocialAccount $account): string
    {
        $id = data_get($account->meta, 'page_id');

        return is_scalar($id) && (string) $id !== '' ? (string) $id : (string) $account->platform_user_id;
    }
}
