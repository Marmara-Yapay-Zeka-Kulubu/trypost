<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Providers\Meta;

use App\Enums\SocialAccount\Platform;
use App\Marmara\Inbox\Exceptions\InboxProviderException;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Providers\InboxItemData;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

/**
 * Instagram comments and DMs for both TryPost Instagram connections:
 *
 * - "Instagram" (Instagram Login, graph.instagram.com, Instagram user token):
 *   instagram_business_manage_comments, instagram_business_manage_messages;
 * - "Instagram (Facebook)" (Facebook Login, graph.facebook.com, Page token):
 *   instagram_manage_comments, instagram_manage_messages, pages_manage_metadata.
 *
 * Standard Access works for accounts whose people have a role on the Meta
 * app; any other account needs Advanced Access (App Review).
 *
 * @see https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/comment-moderation
 * @see https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/messaging-api
 * @see https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/conversations-api
 */
class InstagramInboxProvider extends MetaInboxProvider
{
    /** Instagram's comment length limit. */
    public const int MAX_COMMENT_LENGTH = 2200;

    /** "Message text must be UTF-8 and be a 1000 bytes or less." */
    public const int MAX_MESSAGE_BYTES = 1000;

    public function key(): string
    {
        return 'instagram';
    }

    public function handles(SocialAccount $account): bool
    {
        return in_array($account->platform, [Platform::Instagram, Platform::InstagramFacebook], true)
            && filled($account->platform_user_id);
    }

    public function missingPermission(SocialAccount $account, string $kind): ?string
    {
        $missing = $this->missing($account, $kind);

        return $missing === null ? null : $missing.' (Refresh connection; requires Meta App Review advanced access for accounts without a role on the app)';
    }

    public function missingReplyPermission(SocialAccount $account, string $kind): ?string
    {
        return $this->missingPermission($account, $kind);
    }

    public function reply(InboxItem $item, string $text, string $idempotencyKey): string
    {
        $account = $this->owner($item);

        if ($item->kind === InboxItem::KIND_COMMENT) {
            $answer = $this->graph->post($account, $this->rootCommentId($item).'/replies', ['message' => $text], 'comment reply');

            return (string) data_get($answer, 'id');
        }

        $answer = $account->platform === Platform::Instagram
            ? $this->graph->post($account, $account->platform_user_id.'/messages', [
                'recipient' => ['id' => $this->recipientId($item)],
                'message' => ['text' => $text],
            ], 'message reply')
            : $this->graph->post($account, $this->pageId($account).'/messages', [
                'recipient' => ['id' => $this->recipientId($item)],
                'messaging_type' => 'RESPONSE',
                'message' => ['text' => $text],
            ], 'message reply');

        return (string) (data_get($answer, 'message_id') ?? data_get($answer, 'id'));
    }

    public function hide(InboxItem $item): void
    {
        $this->graph->post($this->owner($item), $item->external_id, ['hide' => true], 'hide comment');
    }

    public function replyTextProblem(InboxItem $item, string $text): ?string
    {
        if ($item->kind === InboxItem::KIND_MESSAGE) {
            return strlen($text) > self::MAX_MESSAGE_BYTES ? 'Instagram messages take up to 1000 bytes' : null;
        }

        return mb_strlen($text) > self::MAX_COMMENT_LENGTH ? 'Instagram comments take up to '.self::MAX_COMMENT_LENGTH.' characters' : null;
    }

    protected function listPosts(SocialAccount $account): array
    {
        $answer = $this->graph->get($account, $account->platform_user_id.'/media', [
            'fields' => 'id,caption,permalink,timestamp',
            'limit' => 25,
        ], 'media list');

        return collect((array) data_get($answer, 'data', []))
            ->filter(fn (mixed $media): bool => is_string(data_get($media, 'id')))
            ->map(fn (mixed $media): array => [
                'id' => (string) data_get($media, 'id'),
                'text' => is_string(data_get($media, 'caption')) ? mb_substr((string) data_get($media, 'caption'), 0, 280) : null,
                'permalink' => is_string(data_get($media, 'permalink')) ? (string) data_get($media, 'permalink') : null,
                'at' => is_string(data_get($media, 'timestamp')) ? (string) data_get($media, 'timestamp') : null,
            ])
            ->values()
            ->all();
    }

    protected function postComments(SocialAccount $account, array $post): array
    {
        $fields = 'id,text,timestamp,username,from,parent_id,hidden';
        $answer = $this->graph->get($account, $post['id'].'/comments', [
            'fields' => $fields.',replies{'.$fields.'}',
            'limit' => 50,
        ], 'comments');
        $selfIds = $this->selfIds($account);
        $canReply = $this->missingReplyPermission($account, InboxItem::KIND_COMMENT) === null;
        $items = [];

        foreach ((array) data_get($answer, 'data', []) as $comment) {
            $items[] = $this->mapComment((array) $comment, $post, $selfIds, $canReply, null);

            foreach ((array) data_get($comment, 'replies.data', []) as $reply) {
                $items[] = $this->mapComment((array) $reply, $post, $selfIds, $canReply, (string) data_get($comment, 'id'));
            }
        }

        return array_values(array_filter($items));
    }

    protected function conversationsPath(SocialAccount $account): string
    {
        return $account->platform === Platform::Instagram
            ? $account->platform_user_id.'/conversations'
            : $this->pageId($account).'/conversations';
    }

    protected function conversationsPlatform(): string
    {
        return 'instagram';
    }

    protected function selfIds(SocialAccount $account): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
            [$account->platform_user_id, data_get($account->meta, 'ig_user_id'), data_get($account->meta, 'page_id')],
        )));
    }

    /**
     * @param  array<string, mixed>  $comment
     * @param  array{id: string, text: string|null, permalink: string|null, at: string|null}  $post
     * @param  list<string>  $selfIds
     */
    private function mapComment(array $comment, array $post, array $selfIds, bool $canReply, ?string $parent): ?InboxItemData
    {
        $id = data_get($comment, 'id');
        $timestamp = data_get($comment, 'timestamp');

        if (! is_string($id) || ! is_string($timestamp)) {
            return null;
        }

        $parent = $parent ?? (is_string(data_get($comment, 'parent_id')) ? (string) data_get($comment, 'parent_id') : null);
        $from = (string) data_get($comment, 'from.id', '');
        $username = data_get($comment, 'username') ?? data_get($comment, 'from.username');
        $text = data_get($comment, 'text');

        return new InboxItemData(
            kind: InboxItem::KIND_COMMENT,
            externalId: $id,
            receivedAt: CarbonImmutable::parse($timestamp)->utc(),
            text: is_string($text) ? $text : null,
            threadId: $parent ?? $id,
            parentExternalId: $parent,
            postExternalId: $post['id'],
            postExcerpt: $post['text'],
            authorId: $from !== '' ? $from : null,
            authorName: is_string($username) ? $username : null,
            authorHandle: is_string($username) ? $username : null,
            permalink: $post['permalink'],
            canReply: $canReply,
            fromSelf: $from !== '' && in_array($from, $selfIds, true),
            hidden: (bool) data_get($comment, 'hidden', false),
        );
    }

    private function missing(SocialAccount $account, string $kind): ?string
    {
        $instagramLogin = $account->platform === Platform::Instagram;

        return match ($kind) {
            InboxItem::KIND_COMMENT => $account->missingScope($instagramLogin ? 'instagram_business_manage_comments' : 'instagram_manage_comments'),
            InboxItem::KIND_MESSAGE => $instagramLogin
                ? $account->missingScope('instagram_business_manage_messages')
                : ($this->pageIdOrNull($account) === null ? 'a linked Facebook Page (reconnect through Facebook)' : $account->missingScope('instagram_manage_messages', 'pages_manage_metadata')),
            default => 'unknown kind',
        };
    }

    private function pageId(SocialAccount $account): string
    {
        return $this->pageIdOrNull($account) ?? throw new InboxProviderException('needs_permission', 'needs a linked Facebook Page (reconnect through Facebook)');
    }

    private function pageIdOrNull(SocialAccount $account): ?string
    {
        $id = data_get($account->meta, 'page_id');

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }
}
