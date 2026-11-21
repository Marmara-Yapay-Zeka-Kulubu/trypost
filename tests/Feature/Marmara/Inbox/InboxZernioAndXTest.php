<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\Webhook\EventType;
use App\Jobs\DispatchWebhook;
use App\Marmara\Inbox\Actions\SendInboxReply;
use App\Marmara\Inbox\Console\InboxRaw;
use App\Marmara\Inbox\Exceptions\InboxReplyRefused;
use App\Marmara\Inbox\InboxProviders;
use App\Marmara\Inbox\InboxSync;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Models\InboxSyncState;
use App\Marmara\Inbox\Providers\Meta\InstagramInboxProvider;
use App\Marmara\Inbox\Providers\XInboxProvider;
use App\Marmara\Inbox\Providers\ZernioInboxProvider;
use App\Marmara\Inbox\Support\InboxMessageText;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const INBOX_ZERNIO_ACCOUNT = '7b1c2d3e4f5a6b7c8d9e0f1a';
const INBOX_X_USER = '1500000000000000001';

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00 UTC'));

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->tiktok = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'marmaraai',
    ]);

    config()->set('marmara-zernio.enabled', true);
    config()->set('marmara-zernio.api_key', 'sk_test_inbox');
    config()->set('marmara-zernio.base_url', 'https://zernio.com/api/v1');
    config()->set('marmara-zernio.accounts', ['tiktok' => [$this->tiktok->id => INBOX_ZERNIO_ACCOUNT]]);
    config()->set('marmara-inbox.enabled', true);
    config()->set('marmara-inbox.providers.zernio', true);
    config()->set('marmara-inbox.zernio.accounts', []);

    app()->instance('marmara-inbox-test.zernio', []);

    Http::fake(function (Request $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $routes = [
            'GET /api/v1/inbox/comments' => [[
                'data' => [[
                    'id' => 'zpost1', 'platform' => 'tiktok', 'accountId' => INBOX_ZERNIO_ACCOUNT, 'content' => 'Yeni bölüm yayında!',
                    'permalink' => 'https://www.tiktok.com/@marmaraai/video/7400000000000000001', 'createdTime' => '2026-11-16T10:00:00Z', 'commentCount' => 2,
                ]],
                'pagination' => ['hasMore' => false, 'nextCursor' => null],
                'meta' => ['accountsQueried' => 1, 'accountsFailed' => 0, 'failedAccounts' => []],
            ]],
            'GET /api/v1/inbox/comments/zpost1' => [[
                'status' => 'success',
                'comments' => [[
                    'id' => 'c1', 'message' => 'Süper anlatım!', 'createdTime' => '2026-11-17T09:00:00Z',
                    'from' => ['id' => 'u1', 'name' => 'Zeynep', 'username' => 'zeynep.ai', 'picture' => null, 'isOwner' => false],
                    'canReply' => true, 'canHide' => true, 'isHidden' => false, 'parentId' => null, 'url' => null,
                    'replies' => [[
                        'id' => 'c2', 'message' => 'Teşekkürler!', 'createdTime' => '2026-11-17T09:05:00Z',
                        'from' => ['id' => 'me', 'username' => 'marmaraai', 'isOwner' => true], 'parentId' => 'c1', 'canReply' => true,
                    ]],
                ]],
                'pagination' => ['hasMore' => false],
            ]],
            'GET /api/v1/inbox/conversations' => [[
                'data' => [[
                    'id' => 'conv1', 'platform' => 'tiktok', 'accountId' => INBOX_ZERNIO_ACCOUNT, 'participantId' => 'p1',
                    'participantName' => 'Can', 'participantPicture' => null, 'updatedTime' => '2026-11-17T11:00:00Z', 'url' => null,
                ]],
                'pagination' => ['hasMore' => false, 'nextCursor' => null],
                'meta' => ['failedAccounts' => []],
            ]],
            'GET /api/v1/inbox/conversations/conv1/messages' => [[
                'status' => 'success',
                'messages' => [
                    ['id' => 'msg2', 'conversationId' => 'conv1', 'message' => 'Kulübe nasıl katılırım?', 'senderId' => 'p1', 'senderName' => 'Can', 'direction' => 'incoming', 'createdAt' => '2026-11-17T11:00:00Z'],
                    ['id' => 'msg1', 'conversationId' => 'conv1', 'message' => 'Selam!', 'senderId' => 'me', 'direction' => 'outgoing', 'createdAt' => '2026-11-17T10:00:00Z'],
                ],
                'pagination' => ['hasMore' => false, 'nextCursor' => null],
            ]],
            'POST /api/v1/inbox/comments/zpost1' => [['success' => true, 'data' => ['commentId' => 'c3', 'isReply' => true]]],
            'POST /api/v1/inbox/conversations/conv1/messages' => [['success' => true, 'data' => ['messageId' => 'msg3', 'conversationId' => 'conv1']]],
            'GET /2/users/'.INBOX_X_USER.'/mentions' => [[
                'data' => [
                    ['id' => '1800000000000000002', 'text' => '@marmaraai etkinlik saat kaçta?', 'created_at' => '2026-11-17T10:00:00.000Z', 'author_id' => '42', 'conversation_id' => '1800000000000000001'],
                    ['id' => '1800000000000000003', 'text' => 'Kendimize cevap', 'created_at' => '2026-11-17T10:05:00.000Z', 'author_id' => INBOX_X_USER, 'conversation_id' => '1800000000000000001'],
                ],
                'includes' => ['users' => [['id' => '42', 'name' => 'Deniz', 'username' => 'deniz_ml']]],
                'meta' => ['newest_id' => '1800000000000000003', 'result_count' => 2],
            ]],
            'POST /2/tweets' => [['data' => ['id' => '1800000000000000004', 'text' => '19:00!']], 201],
        ];
        $key = $request->method().' '.$path;
        $answer = app('marmara-inbox-test.zernio')[$key] ?? $routes[$key] ?? null;

        return $answer === null ? null : Http::response($answer[0], $answer[1] ?? 200, $answer[2] ?? []);
    });
});

function inboxZernioOverride(string $route, array $body, int $status = 200): void
{
    app()->instance('marmara-inbox-test.zernio', [...app('marmara-inbox-test.zernio'), $route => [$body, $status]]);
}

function inboxZernioSync(SocialAccount $account, string $kind): int
{
    return app(InboxSync::class)->run($account, app(InboxProviders::class)->for($account), $kind);
}

test('tiktok comments come from zernio without our own reply and answer with an idempotency key', function () {
    expect(app(InboxProviders::class)->for($this->tiktok))->toBeInstanceOf(ZernioInboxProvider::class)
        ->and(inboxZernioSync($this->tiktok, 'comment'))->toBe(1);

    $item = InboxItem::query()->sole();
    expect($item->provider)->toBe('zernio')
        ->and($item->platform)->toBe('tiktok')
        ->and($item->external_id)->toBe('c1')
        ->and($item->author_name)->toBe('Zeynep')
        ->and($item->author_handle)->toBe('zeynep.ai')
        ->and($item->post_external_id)->toBe('zpost1')
        ->and($item->permalink)->toBe('https://www.tiktok.com/@marmaraai/video/7400000000000000001')
        // JSON columns keep no key order on MySQL: compare as a set of keys.
        ->and($item->meta)->toEqual(['zernio_platform' => 'tiktok', 'zernio_account_id' => INBOX_ZERNIO_ACCOUNT]);
    Http::assertSent(fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/inbox/comments')
        && $request['accountId'] === INBOX_ZERNIO_ACCOUNT
        && $request['sortBy'] === 'date'
        && $request->hasHeader('Authorization', 'Bearer sk_test_inbox'));

    $result = SendInboxReply::execute($item, $this->user, 'Teşekkürler Zeynep!', 'tt-1');

    expect($result['reply']->external_id)->toBe('c3');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/inbox/comments/zpost1')
        && $request->header('Idempotency-Key')[0] === $result['reply']->id
        && $request['accountId'] === INBOX_ZERNIO_ACCOUNT
        && $request['commentId'] === 'c1'
        && $request['message'] === 'Teşekkürler Zeynep!');

    expect(fn () => SendInboxReply::execute($item, $this->user, str_repeat('a', 151), 'tt-long'))
        ->toThrow(InboxReplyRefused::class, 'tiktok takes up to 150 characters here');
});

test('tiktok dms come from zernio with the 48 hour window', function () {
    expect(inboxZernioSync($this->tiktok, 'message'))->toBe(1);

    $item = InboxItem::query()->sole();
    expect($item->external_id)->toBe('msg2')
        ->and($item->thread_id)->toBe('conv1')
        ->and($item->author_name)->toBe('Can')
        ->and($item->reply_window_ends_at->toIso8601String())->toBe('2026-11-19T11:00:00+00:00');

    $result = SendInboxReply::execute($item, $this->user, 'Formu doldurman yeterli.', 'tt-dm');

    expect($result['reply']->external_id)->toBe('msg3');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/inbox/conversations/conv1/messages')
        && $request['accountId'] === INBOX_ZERNIO_ACCOUNT
        && $request['message'] === 'Formu doldurman yeterli.');
});

test('a zernio plan without the inbox reports INBOX_REQUIRED as a missing permission', function () {
    inboxZernioOverride('GET /api/v1/inbox/comments', ['error' => 'Inbox access required', 'code' => 'INBOX_REQUIRED'], 403);

    inboxZernioSync($this->tiktok, 'comment');

    $state = InboxSyncState::query()->sole();
    expect($state->status)->toBe(InboxSyncState::STATUS_NEEDS_PERMISSION)
        ->and($state->status_detail)->toContain('INBOX_REQUIRED');
});

test('a tiktok account outside the business app is reported as a platform limitation', function () {
    inboxZernioOverride('GET /api/v1/inbox/conversations', [
        'data' => [],
        'pagination' => ['hasMore' => false, 'nextCursor' => null],
        'meta' => ['failedAccounts' => [['accountId' => INBOX_ZERNIO_ACCOUNT, 'platform' => 'tiktok', 'error' => 'Not a business account', 'code' => 'PLATFORM_LIMITATION']]],
    ]);

    inboxZernioSync($this->tiktok, 'message');

    $state = InboxSyncState::query()->sole();
    expect($state->status)->toBe(InboxSyncState::STATUS_UNSUPPORTED)
        ->and($state->status_detail)->toContain('Business app');
});

test('a zernio 429 backs off until Retry-After', function () {
    app()->instance('marmara-inbox-test.zernio', ['GET /api/v1/inbox/comments' => [['error' => 'Too many requests'], 429, ['Retry-After' => '300']]]);

    inboxZernioSync($this->tiktok, 'comment');

    expect(InboxSyncState::query()->sole()->blocked_until->toIso8601String())->toBe('2026-11-17T12:05:00+00:00');
});

test('zernio is off by default and an explicit inbox mapping wins over the native provider', function () {
    config()->set('marmara-inbox.providers.zernio', false);
    expect(app(InboxProviders::class)->for($this->tiktok))->toBeNull();

    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id, 'username' => 'marmaraai']);
    config()->set('marmara-inbox.providers.zernio', true);
    config()->set('marmara-inbox.providers.instagram', true);
    expect(app(InboxProviders::class)->for($instagram))->not->toBeInstanceOf(ZernioInboxProvider::class);

    config()->set('marmara-inbox.zernio.accounts', ['instagram' => ['@marmaraai' => INBOX_ZERNIO_ACCOUNT]]);
    expect(app(InboxProviders::class)->for($instagram))->toBeInstanceOf(ZernioInboxProvider::class);
});

test('the zernio webhook receiver stores a signed comment once and fires inbox.item_received', function () {
    config()->set('marmara-inbox.zernio.webhook_secret', 'whsec_inbox');
    Webhook::factory()->create(['workspace_id' => $this->workspace->id, 'events' => [EventType::InboxItemReceived->value]]);
    $body = json_encode([
        'id' => 'evt_1',
        'event' => 'comment.received',
        'comment' => [
            'id' => 'c9', 'postId' => 'zpost1', 'platformPostId' => '7400000000000000001', 'platform' => 'tiktok', 'text' => 'Canlı yayın ne zaman?',
            'author' => ['id' => 'u9', 'username' => 'mert', 'name' => 'Mert', 'picture' => null],
            'createdAt' => '2026-11-17T11:59:00Z', 'isReply' => false, 'parentCommentId' => null,
        ],
        'post' => ['id' => 'zpost1', 'platformPostId' => '7400000000000000001', 'content' => 'Yeni bölüm yayında!', 'imageUrl' => null, 'permalink' => 'https://www.tiktok.com/@marmaraai/video/7400000000000000001'],
        'account' => ['id' => INBOX_ZERNIO_ACCOUNT, 'accountId' => INBOX_ZERNIO_ACCOUNT, 'platform' => 'tiktok', 'username' => 'marmaraai'],
        'timestamp' => '2026-11-17T11:59:01Z',
    ]);
    $send = fn (string $signature) => $this->call('POST', '/api/marmara/zernio/inbox-webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ZERNIO_SIGNATURE' => $signature,
    ], $body);

    $send('0000')->assertStatus(401);
    $send(hash_hmac('sha256', $body, 'whsec_inbox'))->assertOk()->assertJson(['status' => 'ok']);
    // Zernio delivers at least once: the retry changes nothing.
    $send(hash_hmac('sha256', $body, 'whsec_inbox'))->assertOk()->assertJson(['status' => 'duplicate']);

    $item = InboxItem::query()->sole();
    expect($item->external_id)->toBe('c9')
        ->and($item->author_name)->toBe('Mert')
        ->and($item->post_external_id)->toBe('7400000000000000001');
    Queue::assertPushed(DispatchWebhook::class, 1);
});

/**
 * A Zernio message.received delivery for the polled message msg2: the
 * webhook's `message.id` is Zernio's internal id, the list API's id arrives
 * as `message.platformMessageId`.
 */
function inboxZernioMessageWebhook(object $test, string $eventId): void
{
    $body = json_encode([
        'id' => $eventId,
        'event' => 'message.received',
        'message' => [
            'id' => '665f1c0a9b8e7d6c5b4a3921', 'conversationId' => 'conv1', 'platform' => 'tiktok', 'platformMessageId' => 'msg2',
            'direction' => 'incoming', 'text' => 'Kulübe nasıl katılırım?', 'attachments' => [],
            'sender' => ['id' => 'p1', 'name' => 'Can'], 'sentAt' => '2026-11-17T11:00:00Z', 'isRead' => false,
        ],
        'conversation' => ['id' => 'conv1'],
        'account' => ['id' => INBOX_ZERNIO_ACCOUNT, 'accountId' => INBOX_ZERNIO_ACCOUNT, 'platform' => 'tiktok'],
        'timestamp' => '2026-11-17T11:00:01Z',
    ]);

    $test->call('POST', '/api/marmara/zernio/inbox-webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ZERNIO_SIGNATURE' => hash_hmac('sha256', $body, 'whsec_inbox'),
    ], $body)->assertOk();
}

test('a dm read by polling and then delivered by the webhook is one item and one event', function () {
    config()->set('marmara-inbox.zernio.webhook_secret', 'whsec_inbox');
    Webhook::factory()->create(['workspace_id' => $this->workspace->id, 'events' => [EventType::InboxItemReceived->value]]);

    inboxZernioSync($this->tiktok, 'message');
    inboxZernioMessageWebhook($this, 'evt_m1');

    $item = InboxItem::query()->sole();
    expect($item->external_id)->toBe('msg2')
        ->and($item->meta['zernio_message_id'])->toBe('665f1c0a9b8e7d6c5b4a3921');
    Queue::assertPushed(DispatchWebhook::class, 1);
});

test('a dm delivered by the webhook and then polled is one item and one event', function () {
    config()->set('marmara-inbox.zernio.webhook_secret', 'whsec_inbox');
    Webhook::factory()->create(['workspace_id' => $this->workspace->id, 'events' => [EventType::InboxItemReceived->value]]);

    inboxZernioMessageWebhook($this, 'evt_m2');
    expect(inboxZernioSync($this->tiktok, 'message'))->toBe(0);

    expect(InboxItem::query()->sole()->external_id)->toBe('msg2');
    Queue::assertPushed(DispatchWebhook::class, 1);
});

test('a dm stored under zernio\'s internal id moves to the platform id instead of doubling', function () {
    config()->set('marmara-inbox.zernio.webhook_secret', 'whsec_inbox');
    $legacy = InboxItem::query()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $this->tiktok->id,
        'provider' => 'zernio',
        'platform' => 'tiktok',
        'kind' => 'message',
        'external_id' => '665f1c0a9b8e7d6c5b4a3921',
        'thread_id' => 'conv1',
        'text' => 'Kulübe nasıl katılırım?',
        'received_at' => now()->subHour(),
        'can_reply' => true,
    ]);

    inboxZernioMessageWebhook($this, 'evt_m3');
    expect(inboxZernioSync($this->tiktok, 'message'))->toBe(0);

    expect(InboxItem::query()->sole()->is($legacy))->toBeTrue()
        ->and($legacy->fresh()->external_id)->toBe('msg2');
    Queue::assertNotPushed(DispatchWebhook::class);
});

test('the zernio webhook receiver is off without its secret', function () {
    $this->call('POST', '/api/marmara/zernio/inbox-webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertNotFound();
});

test('x mentions are read through the native connection and answered as a reply post', function () {
    config()->set('trypost.platforms.x.api', 'https://api.x.com/2');
    config()->set('marmara-inbox.providers.x', true);
    $x = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => INBOX_X_USER,
        'username' => 'marmaraai',
        'token_expires_at' => now()->addHour(),
    ]);

    expect(app(InboxProviders::class)->for($x))->toBeInstanceOf(XInboxProvider::class)
        ->and(inboxZernioSync($x, 'comment'))->toBe(1);

    $item = InboxItem::query()->sole();
    expect($item->author_handle)->toBe('deniz_ml')
        ->and($item->permalink)->toBe('https://x.com/i/web/status/1800000000000000002')
        ->and(InboxSyncState::query()->sole()->cursor)->toBe(['since_id' => '1800000000000000003']);

    SendInboxReply::execute($item, $this->user, '19:00!', 'x-1');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/2/tweets')
        && $request['reply'] === ['in_reply_to_tweet_id' => '1800000000000000002']);

    // DMs need dm.read, which the default X connect does not ask for.
    inboxZernioSync($x, 'message');
    expect(InboxSyncState::query()->where('kind', 'message')->sole()->status_detail)->toContain('dm.read');
});

const INBOX_ZERNIO_IG_ACCOUNT = '8c2d3e4f5a6b7c8d9e0f1a2b';

/**
 * An Instagram channel read through Zernio, and its conversation with the
 * summit account: a story mention (18:03) and their reaction to our thank
 * you (18:04), shaped like GET /v1/inbox/conversations/{id}/messages.
 */
function inboxZernioInstagram(object $test): SocialAccount
{
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $test->workspace->id, 'username' => 'marmaraai']);
    config()->set('marmara-inbox.zernio.accounts', ['instagram' => ['@marmaraai' => INBOX_ZERNIO_IG_ACCOUNT]]);

    inboxZernioOverride('GET /api/v1/inbox/conversations', [
        'data' => [[
            'id' => 'igconv1', 'platform' => 'instagram', 'accountId' => INBOX_ZERNIO_IG_ACCOUNT, 'participantId' => 'ig555',
            'participantName' => 'yonetimveliderlikzirvesi', 'participantPicture' => null, 'updatedTime' => '2026-11-17T18:04:00Z', 'url' => null,
        ]],
        'pagination' => ['hasMore' => false, 'nextCursor' => null],
        'meta' => ['failedAccounts' => []],
    ]);
    inboxZernioOverride('GET /api/v1/inbox/conversations/igconv1/messages', [
        'status' => 'success',
        'messages' => [
            [
                'id' => 'aWdfmid_ours', 'conversationId' => 'igconv1', 'platform' => 'instagram', 'message' => 'Teşekkürler 🙏', 'senderId' => 'me',
                'direction' => 'outgoing', 'createdAt' => '2026-11-17T18:03:30Z', 'attachments' => [],
                'reactions' => [['emoji' => '😍', 'fromMe' => false, 'reactedAt' => '2026-11-17T18:04:00Z']],
            ],
            [
                'id' => 'aWdfmid_mention', 'conversationId' => 'igconv1', 'platform' => 'instagram', 'message' => null, 'senderId' => 'ig555',
                'senderName' => 'Yönetim ve Liderlik Zirvesi', 'direction' => 'incoming', 'createdAt' => '2026-11-17T18:03:00Z',
                'isStoryMention' => true, 'reactions' => [],
                'attachments' => [['id' => 'att1', 'type' => 'share', 'originalType' => 'story_mention', 'url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1']],
            ],
        ],
        'pagination' => ['hasMore' => false, 'nextCursor' => null],
    ]);

    return $instagram;
}

test('an instagram story mention and a reaction from zernio are two readable items and echo gets their text', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 18:10:00 UTC'));
    Webhook::factory()->create(['workspace_id' => $this->workspace->id, 'events' => [EventType::InboxItemReceived->value]]);
    $instagram = inboxZernioInstagram($this);

    expect(inboxZernioSync($instagram, 'message'))->toBe(2);

    $mention = InboxItem::query()->where('external_id', 'aWdfmid_mention')->sole();
    $reaction = InboxItem::query()->where('external_id', 'reaction:aWdfmid_ours:😍')->sole();

    expect($mention->text)->toBe("📸 Hikâyende bizi etiketledi\nhttps://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1")
        ->and($mention->meta['message_parts'])->toBe(['story_mention'])
        ->and($mention->meta['story_url'])->toBe('https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1')
        ->and($reaction->text)->toBe('😍 Mesaja tepki verdi')
        ->and($reaction->author_external_id)->toBe('ig555')
        ->and($reaction->meta['reacted_message_id'])->toBe('aWdfmid_ours')
        ->and(InboxItem::query()->where('external_id', 'aWdfmid_ours')->exists())->toBeFalse();

    // The text Echo receives is the rendered one, emoji intact.
    Queue::assertPushed(DispatchWebhook::class, 2);
    Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->payload['item']['text'] === '😍 Mesaja tepki verdi'
        && json_decode((string) json_encode($job->payload), true)['item']['text'] === '😍 Mesaja tepki verdi');

    // The next run reads the same messages again: nothing new.
    InboxSyncState::query()->update(['cursor' => null]);
    expect(inboxZernioSync($instagram, 'message'))->toBe(0)
        ->and(InboxItem::query()->count())->toBe(2);
});

function inboxZernioSignedPost(object $test, array $payload): void
{
    $body = (string) json_encode($payload);

    $test->call('POST', '/api/marmara/zernio/inbox-webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ZERNIO_SIGNATURE' => hash_hmac('sha256', $body, 'whsec_inbox'),
    ], $body)->assertOk();
}

test('the zernio webhook renders a story mention and stores a reaction under the id polling gives it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 18:05:00 UTC'));
    config()->set('marmara-inbox.zernio.webhook_secret', 'whsec_inbox');
    $instagram = inboxZernioInstagram($this);
    $account = ['id' => INBOX_ZERNIO_IG_ACCOUNT, 'accountId' => INBOX_ZERNIO_IG_ACCOUNT, 'platform' => 'instagram'];
    $conversation = ['id' => 'igconv1', 'participantId' => 'ig555', 'participantName' => 'yonetimveliderlikzirvesi'];

    inboxZernioSignedPost($this, [
        'id' => 'evt_mention', 'event' => 'message.received', 'account' => $account, 'conversation' => $conversation, 'timestamp' => '2026-11-17T18:03:01Z',
        'message' => [
            'id' => '665f00000000000000000001', 'conversationId' => 'igconv1', 'platform' => 'instagram', 'platformMessageId' => 'aWdfmid_mention',
            'direction' => 'incoming', 'text' => null, 'sentAt' => '2026-11-17T18:03:00Z', 'isRead' => false,
            'attachments' => [['type' => 'share', 'originalType' => 'story_mention', 'url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1']],
            'sender' => ['id' => 'ig555', 'name' => 'Yönetim ve Liderlik Zirvesi', 'username' => 'yonetimveliderlikzirvesi'],
        ],
    ]);
    $reaction = ['emoji' => '😍', 'action' => 'added', 'platformMessageId' => 'aWdfmid_ours', 'reactedAt' => '2026-11-17T18:04:00Z',
        'sender' => ['id' => 'ig555', 'name' => 'Yönetim ve Liderlik Zirvesi']];
    inboxZernioSignedPost($this, ['id' => 'evt_reaction', 'event' => 'reaction.received', 'account' => $account, 'conversation' => $conversation, 'reaction' => $reaction, 'timestamp' => '2026-11-17T18:04:01Z']);
    // Our own reaction and a removal are not items.
    inboxZernioSignedPost($this, ['id' => 'evt_ours', 'event' => 'reaction.received', 'account' => $account, 'conversation' => $conversation, 'reaction' => [...$reaction, 'emoji' => '👍', 'sender' => ['id' => 'me']], 'timestamp' => '2026-11-17T18:04:02Z']);
    inboxZernioSignedPost($this, ['id' => 'evt_removed', 'event' => 'reaction.received', 'account' => $account, 'conversation' => $conversation, 'reaction' => [...$reaction, 'emoji' => '🔥', 'action' => 'removed'], 'timestamp' => '2026-11-17T18:04:03Z']);

    expect(InboxItem::query()->pluck('text', 'external_id')->all())->toEqual([
        'aWdfmid_mention' => "📸 Hikâyende bizi etiketledi\nhttps://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1",
        'reaction:aWdfmid_ours:😍' => '😍 Mesaja tepki verdi',
    ]);

    // Polling the same conversation afterwards adds nothing.
    expect(inboxZernioSync($instagram, 'message'))->toBe(0)
        ->and(InboxItem::query()->count())->toBe(2);
});

test('a dm polled while zernio kept no content gets its text from the webhook', function () {
    config()->set('marmara-inbox.zernio.webhook_secret', 'whsec_inbox');
    inboxZernioOverride('GET /api/v1/inbox/conversations/conv1/messages', [
        'status' => 'success',
        'messages' => [['id' => 'msg2', 'conversationId' => 'conv1', 'message' => null, 'contentNotRetained' => true, 'attachments' => [], 'senderId' => 'p1', 'direction' => 'incoming', 'createdAt' => '2026-11-17T11:00:00Z']],
        'pagination' => ['hasMore' => false],
    ]);

    inboxZernioSync($this->tiktok, 'message');
    expect(InboxItem::query()->sole()->text)->toBe(InboxMessageText::NOT_RETAINED);

    inboxZernioMessageWebhook($this, 'evt_m9');

    $item = InboxItem::query()->sole();
    expect($item->text)->toBe('Kulübe nasıl katılırım?')
        ->and($item->meta['content_not_retained'])->toBeFalse();
});

test('one instagram account connected twice is read by one channel only', function () {
    config()->set('marmara-inbox.providers.instagram', true);
    $native = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id, 'username' => 'marmaraai', 'created_at' => now()->subDay()]);
    $viaFacebook = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id, 'platform' => Platform::InstagramFacebook, 'username' => 'MarmaraAI', 'created_at' => now(),
    ]);
    $providers = app(InboxProviders::class);

    // Both native: the one connected first reads.
    expect($providers->for($native))->toBeInstanceOf(InstagramInboxProvider::class)
        ->and($providers->for($viaFacebook))->toBeNull()
        ->and($providers->readThroughTwin($viaFacebook)?->id)->toBe($native->id);

    // The Facebook-login channel mapped to Zernio: Zernio reads, the native one stops.
    config()->set('marmara-inbox.zernio.accounts', ['instagram-facebook' => ['@marmaraai' => INBOX_ZERNIO_IG_ACCOUNT]]);
    expect($providers->for($viaFacebook))->toBeInstanceOf(ZernioInboxProvider::class)
        ->and($providers->for($native))->toBeNull();

    // Another account of the workspace is not a twin.
    $other = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id, 'username' => 'baska.hesap']);
    expect($providers->for($other))->toBeInstanceOf(InstagramInboxProvider::class);
});

test('a message a channel already has from another provider is not stored again', function () {
    $instagram = inboxZernioInstagram($this);
    InboxItem::query()->create([
        'workspace_id' => $this->workspace->id, 'social_account_id' => $instagram->id, 'provider' => 'instagram', 'platform' => 'instagram',
        'kind' => 'message', 'external_id' => 'aWdfmid_mention', 'thread_id' => 'aWdfZAG06', 'text' => null, 'received_at' => '2026-11-17T18:03:00Z',
    ]);

    expect(inboxZernioSync($instagram, 'message'))->toBe(1);

    expect(InboxItem::query()->where('external_id', 'aWdfmid_mention')->count())->toBe(1)
        ->and(InboxItem::query()->where('external_id', 'aWdfmid_mention')->sole()->text)->toStartWith('📸 Hikâyende bizi etiketledi');
});

test('marmara:inbox:raw prints the stored meta and zernio\'s raw message without the key', function () {
    $instagram = inboxZernioInstagram($this);
    inboxZernioSync($instagram, 'message');
    $item = InboxItem::query()->where('external_id', 'aWdfmid_mention')->sole();

    $this->artisan('marmara:inbox:raw', ['item' => $item->id])
        ->expectsOutputToContain('"external_id": "aWdfmid_mention"')
        ->expectsOutputToContain('GET /inbox/conversations/igconv1/messages')
        ->expectsOutputToContain('"originalType": "story_mention"')
        ->doesntExpectOutputToContain('sk_test_inbox')
        ->assertSuccessful();

    $this->artisan('marmara:inbox:raw', ['item' => $item->id, '--stored' => true])
        ->doesntExpectOutputToContain('Full answer')
        ->assertSuccessful();

    expect(InboxRaw::redact(['access_token' => 'EAAB', 'url' => 'https://graph.facebook.com/x?access_token=EAAB&a=1', 'h' => 'Bearer abc.def']))
        ->toBe(['access_token' => '[redacted]', 'url' => 'https://graph.facebook.com/x?access_token=[redacted]&a=1', 'h' => 'Bearer [redacted]']);
});
