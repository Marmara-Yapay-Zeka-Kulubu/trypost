<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Http;

use App\Marmara\Inbox\InboxConfig;
use App\Marmara\Inbox\InboxItemStore;
use App\Marmara\Inbox\InboxProviders;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Models\InboxSyncState;
use App\Marmara\Inbox\Providers\InboxItemData;
use App\Marmara\Inbox\Providers\ZernioInboxProvider;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Optional receiver for Zernio's `comment.received` and `message.received`
 * webhooks (real time on Instagram, Facebook, Threads and TikTok), next to
 * polling, which stays the fallback. Off until
 * MARMARA_INBOX_ZERNIO_WEBHOOK_SECRET is set.
 *
 * Signed with `X-Zernio-Signature`: HMAC-SHA256 of the raw body, lowercase
 * hex. Delivery is at least once, so events are deduped by `id` (and items
 * by their external id). Zernio keeps no content (zero retention), so the
 * item is stored from the payload; a payload this receiver cannot map only
 * makes the channel's next poll due now.
 *
 * @see https://docs.zernio.com/webhooks/inbox/comment-received
 */
class ZernioInboxWebhookController
{
    public function __invoke(Request $request, InboxConfig $config, InboxProviders $providers, InboxItemStore $store, ZernioInboxProvider $zernio): JsonResponse
    {
        $secret = $config->zernioWebhookSecret();

        if ($secret === null || ! $config->providerEnabled('zernio')) {
            abort(404);
        }

        $signature = strtolower(trim((string) $request->header('X-Zernio-Signature')));

        if ($signature === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = (array) $request->json()->all();
        $eventId = (string) (data_get($payload, 'id') ?? $request->header('X-Zernio-Event-Id', ''));
        $event = (string) data_get($payload, 'event', '');

        if (! in_array($event, ['comment.received', 'message.received'], true) || (bool) data_get($payload, 'test', false)) {
            return response()->json(['status' => 'ignored']);
        }

        $seenKey = 'marmara-inbox:zernio-event:'.$eventId;

        if ($eventId !== '' && Cache::has($seenKey)) {
            return response()->json(['status' => 'duplicate']);
        }

        $zernioAccountId = (string) (data_get($payload, 'account.accountId') ?? data_get($payload, 'account.id', ''));
        $accounts = SocialAccount::query()->get()->filter(
            fn (SocialAccount $account): bool => $providers->for($account)?->key() === 'zernio' && $zernio->zernioAccountId($account) === $zernioAccountId,
        );

        foreach ($accounts as $account) {
            $data = $event === 'comment.received'
                ? $this->comment($payload, $zernioAccountId)
                : $this->message($payload, $zernioAccountId);

            if ($data !== null) {
                $store->ingest($account, $zernio, $data);

                continue;
            }

            // Unknown shape: poll this channel at the next scheduler tick.
            InboxSyncState::query()
                ->where('social_account_id', $account->id)
                ->where('provider', 'zernio')
                ->where('kind', $event === 'comment.received' ? InboxItem::KIND_COMMENT : InboxItem::KIND_MESSAGE)
                ->update(['last_attempt_at' => null]);
        }

        // Remembered only once handled, so Zernio's retry of a failed delivery is processed.
        if ($eventId !== '') {
            Cache::put($seenKey, true, now()->addDays(7));
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function comment(array $payload, string $zernioAccountId): ?InboxItemData
    {
        $id = data_get($payload, 'comment.id');
        $created = data_get($payload, 'comment.createdAt');
        $post = data_get($payload, 'post.platformPostId') ?? data_get($payload, 'comment.platformPostId') ?? data_get($payload, 'post.id');

        if (! is_string($id) || ! is_string($created) || ! is_string($post)) {
            return null;
        }

        $parent = data_get($payload, 'comment.parentCommentId');
        $parent = is_string($parent) && $parent !== '' ? $parent : null;
        $name = data_get($payload, 'comment.author.name');
        $username = data_get($payload, 'comment.author.username');
        $content = data_get($payload, 'post.content');

        return new InboxItemData(
            kind: InboxItem::KIND_COMMENT,
            externalId: $id,
            receivedAt: CarbonImmutable::parse($created)->utc(),
            text: is_string(data_get($payload, 'comment.text')) ? (string) data_get($payload, 'comment.text') : null,
            threadId: $parent ?? $id,
            parentExternalId: $parent,
            postExternalId: $post,
            postExcerpt: is_string($content) ? mb_substr($content, 0, 280) : null,
            authorId: is_scalar(data_get($payload, 'comment.author.id')) ? (string) data_get($payload, 'comment.author.id') : null,
            authorName: is_string($name) ? $name : (is_string($username) ? $username : null),
            authorHandle: is_string($username) ? $username : null,
            authorAvatarUrl: is_string(data_get($payload, 'comment.author.picture')) ? (string) data_get($payload, 'comment.author.picture') : null,
            permalink: is_string(data_get($payload, 'post.permalink')) ? (string) data_get($payload, 'post.permalink') : null,
            fromSelf: (bool) data_get($payload, 'comment.author.isOwnAccount', false),
            meta: ['zernio_account_id' => $zernioAccountId, 'zernio_platform' => (string) data_get($payload, 'comment.platform', '')],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function message(array $payload, string $zernioAccountId): ?InboxItemData
    {
        $id = data_get($payload, 'message.id');
        $conversation = data_get($payload, 'message.conversationId') ?? data_get($payload, 'conversation.id');
        $created = data_get($payload, 'message.createdAt') ?? data_get($payload, 'message.sentAt') ?? data_get($payload, 'timestamp');

        if (! is_string($id) || ! is_string($conversation) || ! is_string($created)) {
            return null;
        }

        $platform = (string) (data_get($payload, 'message.platform') ?? data_get($payload, 'account.platform', ''));
        $hours = ['instagram' => 24, 'facebook' => 24, 'tiktok' => 48, 'whatsapp' => 24][$platform] ?? null;
        $at = CarbonImmutable::parse($created)->utc();
        $text = data_get($payload, 'message.text') ?? data_get($payload, 'message.message');
        $name = data_get($payload, 'message.sender.name') ?? data_get($payload, 'conversation.participantName');
        $username = data_get($payload, 'message.sender.username');

        return new InboxItemData(
            kind: InboxItem::KIND_MESSAGE,
            externalId: $id,
            receivedAt: $at,
            text: is_string($text) ? $text : null,
            threadId: $conversation,
            authorId: is_scalar(data_get($payload, 'message.sender.id')) ? (string) data_get($payload, 'message.sender.id') : null,
            authorName: is_string($name) ? $name : null,
            authorHandle: is_string($username) ? $username : null,
            replyWindowEndsAt: $hours === null ? null : $at->addHours($hours),
            fromSelf: data_get($payload, 'message.direction') === 'outgoing',
            meta: ['zernio_account_id' => $zernioAccountId, 'zernio_platform' => $platform],
        );
    }
}
