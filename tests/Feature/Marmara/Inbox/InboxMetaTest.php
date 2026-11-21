<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Marmara\Inbox\Actions\SendInboxReply;
use App\Marmara\Inbox\Exceptions\InboxReplyRefused;
use App\Marmara\Inbox\InboxProviders;
use App\Marmara\Inbox\InboxSync;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Models\InboxReply;
use App\Marmara\Inbox\Models\InboxSyncState;
use App\Marmara\Inbox\Providers\Meta\FacebookInboxProvider;
use App\Marmara\Inbox\Providers\Meta\InstagramInboxProvider;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const INBOX_IG_ID = '17841400000000001';
const INBOX_IG_MEDIA = '18000000000000001';
const INBOX_IG_COMMENT = '17900000000000001';
const INBOX_FB_PAGE = '1122334455';

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00 UTC'));

    config()->set('trypost.platforms.instagram.graph_api', 'https://graph.instagram.com/v25.0');
    config()->set('trypost.platforms.instagram-facebook.graph_api', 'https://graph.facebook.com/v25.0');
    config()->set('trypost.platforms.facebook.graph_api', 'https://graph.facebook.com/v25.0');
    config()->set('marmara-inbox.enabled', true);
    config()->set('marmara-inbox.providers.instagram', true);
    config()->set('marmara-inbox.providers.facebook', true);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->instagram = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => INBOX_IG_ID,
        'username' => 'marmaraai',
        'scopes' => ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_comments', 'instagram_business_manage_messages'],
        'token_expires_at' => now()->addDays(50),
    ]);
    $this->facebook = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => INBOX_FB_PAGE,
        'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_read_user_content', 'pages_manage_engagement', 'pages_manage_posts'],
        'meta' => ['page_id' => INBOX_FB_PAGE],
        'token_expires_at' => null,
    ]);

    app()->instance('marmara-inbox-test.meta', []);

    // One fake for the test; routes answer from 'marmara-inbox-test.meta' (path => [body, status, headers]).
    Http::fake(function (Request $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $routes = [
            'GET /v25.0/'.INBOX_IG_ID.'/media' => [['data' => [[
                'id' => INBOX_IG_MEDIA,
                'caption' => 'Hackathon kayıtları açıldı',
                'permalink' => 'https://www.instagram.com/p/ABC123/',
                'timestamp' => '2026-11-16T10:00:00+0000',
            ]]]],
            'GET /v25.0/'.INBOX_IG_MEDIA.'/comments' => [['data' => [[
                'id' => INBOX_IG_COMMENT,
                'text' => 'Son başvuru ne zaman?',
                'timestamp' => '2026-11-17T09:00:00+0000',
                'username' => 'ayse.dev',
                'from' => ['id' => '9001', 'username' => 'ayse.dev'],
                'replies' => ['data' => [[
                    'id' => '17900000000000002',
                    'text' => 'Cuma akşamı!',
                    'timestamp' => '2026-11-17T09:10:00+0000',
                    'username' => 'marmaraai',
                    'from' => ['id' => INBOX_IG_ID, 'username' => 'marmaraai'],
                ]]],
            ]]]],
            'GET /v25.0/'.INBOX_IG_ID.'/conversations' => [['data' => [[
                'id' => 'aWdfZAG06',
                'updated_time' => '2026-11-17T11:00:00+0000',
                'messages' => ['data' => [
                    ['id' => 'm_new', 'created_time' => '2026-11-17T11:00:00+0000', 'from' => ['id' => '555', 'username' => 'mehmet.k'], 'message' => 'Staj var mı?'],
                    ['id' => 'm_ours', 'created_time' => '2026-11-16T09:30:00+0000', 'from' => ['id' => INBOX_IG_ID, 'username' => 'marmaraai'], 'message' => 'Merhaba!'],
                    ['id' => 'm_old', 'created_time' => '2026-11-16T09:00:00+0000', 'from' => ['id' => '555', 'username' => 'mehmet.k'], 'message' => 'Selam'],
                ]],
            ]]]],
            'POST /v25.0/'.INBOX_IG_COMMENT.'/replies' => [['id' => '17900000000000003']],
            'POST /v25.0/'.INBOX_IG_COMMENT => [['success' => true]],
            'POST /v25.0/'.INBOX_IG_ID.'/messages' => [['recipient_id' => '555', 'message_id' => 'm_sent']],
            'GET /v25.0/'.INBOX_FB_PAGE.'/published_posts' => [['data' => [[
                'id' => INBOX_FB_PAGE.'_777',
                'message' => 'Kulüp tanıtım günü',
                'permalink_url' => 'https://www.facebook.com/marmaraai/posts/777',
                'created_time' => '2026-11-16T10:00:00+0000',
            ]]]],
            'GET /v25.0/'.INBOX_FB_PAGE.'_777/comments' => [['data' => [
                [
                    'id' => '777_1',
                    'message' => 'Katılacağım!',
                    'from' => ['id' => '888', 'name' => 'Ali Veli', 'picture' => ['data' => ['url' => 'https://cdn.example/ali.jpg']]],
                    'created_time' => '2026-11-17T09:30:00+0000',
                    'permalink_url' => 'https://www.facebook.com/marmaraai/posts/777?comment_id=1',
                ],
                [
                    'id' => '777_2',
                    'message' => 'Görüşürüz',
                    'from' => ['id' => INBOX_FB_PAGE, 'name' => 'Marmara AI'],
                    'parent' => ['id' => '777_1'],
                    'created_time' => '2026-11-17T09:40:00+0000',
                ],
            ]]],
            'POST /v25.0/777_1/comments' => [['id' => '777_3']],
        ];
        $key = $request->method().' '.$path;
        $answer = app('marmara-inbox-test.meta')[$key] ?? $routes[$key] ?? null;

        return $answer === null ? null : Http::response($answer[0], $answer[1] ?? 200, $answer[2] ?? []);
    });
});

function inboxMetaOverride(string $route, array $body, int $status = 200, array $headers = []): void
{
    app()->instance('marmara-inbox-test.meta', [...app('marmara-inbox-test.meta'), $route => [$body, $status, $headers]]);
}

function inboxMetaSync(SocialAccount $account, string $kind): int
{
    return app(InboxSync::class)->run($account, app(InboxProviders::class)->for($account), $kind);
}

test('instagram comments and replies are mapped and our own reply is skipped', function () {
    expect(app(InboxProviders::class)->for($this->instagram))->toBeInstanceOf(InstagramInboxProvider::class)
        ->and(inboxMetaSync($this->instagram, 'comment'))->toBe(1);

    $item = InboxItem::query()->sole();
    expect($item->platform)->toBe('instagram')
        ->and($item->external_id)->toBe(INBOX_IG_COMMENT)
        ->and($item->author_name)->toBe('ayse.dev')
        ->and($item->author_handle)->toBe('ayse.dev')
        ->and($item->post_external_id)->toBe(INBOX_IG_MEDIA)
        ->and($item->post_excerpt)->toBe('Hackathon kayıtları açıldı')
        ->and($item->permalink)->toBe('https://www.instagram.com/p/ABC123/')
        ->and($item->can_reply)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), INBOX_IG_MEDIA.'/comments')
        && str_contains(urldecode($request->url()), 'replies{id,text,timestamp'));

    // Resumable and deduped: the same page read again adds nothing.
    expect(inboxMetaSync($this->instagram, 'comment'))->toBe(0);
});

test('an instagram comment reply goes to its top-level comment and a hide is sent', function () {
    inboxMetaSync($this->instagram, 'comment');
    $item = InboxItem::query()->sole();

    $result = SendInboxReply::execute($item, $this->user, 'Cuma 23:59!', 'req-1');
    app(InstagramInboxProvider::class)->hide($item);

    expect($result['reply']->status)->toBe(InboxReply::STATUS_SENT)
        ->and($result['reply']->external_id)->toBe('17900000000000003');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), INBOX_IG_COMMENT.'/replies') && $request['message'] === 'Cuma 23:59!');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/v25.0/'.INBOX_IG_COMMENT) && $request['hide'] === true);
});

test('instagram dms keep incoming messages with the 24 hour window of the conversation', function () {
    expect(inboxMetaSync($this->instagram, 'message'))->toBe(2);

    $new = InboxItem::query()->where('external_id', 'm_new')->sole();
    $old = InboxItem::query()->where('external_id', 'm_old')->sole();

    expect(InboxItem::query()->where('external_id', 'm_ours')->exists())->toBeFalse()
        ->and($new->kind)->toBe('message')
        ->and($new->thread_id)->toBe('aWdfZAG06')
        ->and($new->author_external_id)->toBe('555')
        ->and($new->reply_window_ends_at->toIso8601String())->toBe('2026-11-18T11:00:00+00:00')
        // The newest message reopened the window for the whole conversation.
        ->and($old->reply_window_ends_at->toIso8601String())->toBe('2026-11-18T11:00:00+00:00')
        ->and(InboxSyncState::query()->where('kind', 'message')->sole()->cursor)->toBe(['since' => '2026-11-17T11:00:00+00:00']);

    // Nothing newer than the cursor: no item, no change.
    expect(inboxMetaSync($this->instagram, 'message'))->toBe(0);

    $result = SendInboxReply::execute($new, $this->user, 'Evet, başvurular açık.', 'dm-1');

    expect($result['reply']->external_id)->toBe('m_sent');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), INBOX_IG_ID.'/messages')
        && $request['recipient'] === ['id' => '555']
        && $request['message'] === ['text' => 'Evet, başvurular açık.']);
});

test('a dm whose window has closed is refused before any request', function () {
    inboxMetaSync($this->instagram, 'message');
    $item = InboxItem::query()->where('external_id', 'm_new')->sole();
    $this->travelTo(CarbonImmutable::parse('2026-11-18 11:00:01 UTC'));

    expect(fn () => SendInboxReply::execute($item, $this->user, 'Geç kaldık', 'dm-late'))
        ->toThrow(InboxReplyRefused::class, 'The reply window for this conversation has closed.');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    expect(InboxReply::query()->count())->toBe(0);
});

test('meta refusing a send outside the window closes the window and replays as refused', function () {
    inboxMetaSync($this->instagram, 'message');
    $item = InboxItem::query()->where('external_id', 'm_new')->sole();
    inboxMetaOverride('POST /v25.0/'.INBOX_IG_ID.'/messages', ['error' => [
        'message' => '(#10) This message is sent outside of allowed window.',
        'code' => 10,
        'error_subcode' => 2018278,
    ]], 400);

    expect(fn () => SendInboxReply::execute($item, $this->user, 'Merhaba', 'dm-2'))->toThrow(InboxReplyRefused::class);
    expect($item->fresh()->replyWindowClosed())->toBeTrue()
        ->and(InboxReply::query()->sole()->status)->toBe(InboxReply::STATUS_FAILED);

    // The same request id answers the same refusal without a second send.
    expect(fn () => SendInboxReply::execute($item->fresh(), $this->user, 'Merhaba', 'dm-2'))->toThrow(InboxReplyRefused::class);
    Http::assertSentCount(2);
});

test('an instagram reply longer than 1000 bytes is refused as too long', function () {
    inboxMetaSync($this->instagram, 'message');
    $item = InboxItem::query()->where('external_id', 'm_new')->sole();

    expect(fn () => SendInboxReply::execute($item, $this->user, str_repeat('ş', 501), 'long'))
        ->toThrow(InboxReplyRefused::class, 'Instagram messages take up to 1000 bytes');
});

test('an instagram channel without the inbox scopes reports what it needs', function () {
    $this->instagram->update(['scopes' => ['instagram_business_basic', 'instagram_business_content_publish']]);

    inboxMetaSync($this->instagram, 'comment');
    inboxMetaSync($this->instagram, 'message');

    $states = InboxSyncState::query()->get()->keyBy('kind');
    expect($states['comment']->status)->toBe(InboxSyncState::STATUS_NEEDS_PERMISSION)
        ->and($states['comment']->status_detail)->toContain('instagram_business_manage_comments')->toContain('App Review')
        ->and($states['message']->status_detail)->toContain('instagram_business_manage_messages');
    Http::assertNothingSent();
});

test('instagram through facebook login reads dms through the page', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook,
        'platform_user_id' => INBOX_IG_ID.'9',
        'scopes' => ['instagram_basic', 'instagram_manage_comments', 'instagram_manage_messages', 'pages_manage_metadata'],
        'meta' => ['page_id' => INBOX_FB_PAGE],
        'token_expires_at' => null,
    ]);
    inboxMetaOverride('GET /v25.0/'.INBOX_FB_PAGE.'/conversations', ['data' => []]);

    inboxMetaSync($account, 'message');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'graph.facebook.com/v25.0/'.INBOX_FB_PAGE.'/conversations')
        && $request['platform'] === 'instagram');
});

test('facebook page comments are mapped with the commenter and replies go to the comment', function () {
    expect(app(InboxProviders::class)->for($this->facebook))->toBeInstanceOf(FacebookInboxProvider::class)
        ->and(inboxMetaSync($this->facebook, 'comment'))->toBe(1);

    $item = InboxItem::query()->sole();
    expect($item->platform)->toBe('facebook')
        ->and($item->author_name)->toBe('Ali Veli')
        ->and($item->author_avatar_url)->toBe('https://cdn.example/ali.jpg')
        ->and($item->permalink)->toBe('https://www.facebook.com/marmaraai/posts/777?comment_id=1')
        ->and($item->post_external_id)->toBe(INBOX_FB_PAGE.'_777');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '_777/comments') && $request['filter'] === 'stream');

    $result = SendInboxReply::execute($item, $this->user, 'Bekliyoruz!', 'fb-1');
    expect($result['reply']->external_id)->toBe('777_3');
});

test('facebook messenger needs pages_messaging', function () {
    inboxMetaSync($this->facebook, 'message');

    expect(InboxSyncState::query()->sole()->status_detail)->toContain('pages_messaging');
});

test('a graph rate limit backs the channel off until meta says it can call again', function () {
    inboxMetaOverride('GET /v25.0/'.INBOX_FB_PAGE.'/published_posts', ['error' => ['message' => 'Application request limit reached', 'code' => 4]], 400, [
        'X-Business-Use-Case-Usage' => json_encode([INBOX_FB_PAGE => [['type' => 'pages', 'call_count' => 100, 'estimated_time_to_regain_access' => 17]]]),
    ]);

    inboxMetaSync($this->facebook, 'comment');

    $state = InboxSyncState::query()->sole();
    expect($state->status)->toBe(InboxSyncState::STATUS_RATE_LIMITED)
        ->and($state->blocked_until->toIso8601String())->toBe('2026-11-17T12:17:00+00:00')
        ->and(app(InboxSync::class)->due($state))->toBeFalse();
});

test('meta providers stay off without their flags', function () {
    config()->set('marmara-inbox.providers.instagram', false);
    config()->set('marmara-inbox.providers.facebook', false);

    expect(app(InboxProviders::class)->for($this->instagram))->toBeNull()
        ->and(app(InboxProviders::class)->for($this->facebook))->toBeNull();

    config()->set('marmara-inbox.enabled', false);
    config()->set('marmara-inbox.providers.instagram', true);

    expect(app(InboxProviders::class)->for($this->instagram))->toBeNull();
});

test('instagram dms read story mentions, story replies, media and reactions as text', function () {
    inboxMetaOverride('GET /v25.0/'.INBOX_IG_ID.'/conversations', ['data' => [[
        'id' => 'aWdfZAG07',
        'updated_time' => '2026-11-17T11:04:00+0000',
        'messages' => ['data' => [
            ['id' => 'm_ours', 'created_time' => '2026-11-17T11:03:30+0000', 'from' => ['id' => INBOX_IG_ID, 'username' => 'marmaraai'], 'message' => 'Teşekkürler 🙏',
                'reactions' => ['data' => [['reaction' => '😍', 'users' => [['id' => '556', 'username' => 'zirve']]], ['reaction' => '👍', 'users' => [['id' => INBOX_IG_ID, 'username' => 'marmaraai']]]]]],
            ['id' => 'm_mention', 'created_time' => '2026-11-17T11:03:00+0000', 'from' => ['id' => '556', 'username' => 'zirve'], 'message' => '',
                'story' => ['mention' => ['link' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=2', 'id' => '17901']]],
            ['id' => 'm_reply', 'created_time' => '2026-11-17T11:02:00+0000', 'from' => ['id' => '556', 'username' => 'zirve'], 'message' => '🔥🔥',
                'story' => ['reply_to' => ['link' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=3', 'id' => '17902']]],
            ['id' => 'm_photo', 'created_time' => '2026-11-17T11:01:00+0000', 'from' => ['id' => '556', 'username' => 'zirve'], 'message' => 'Afiş',
                'attachments' => ['data' => [['id' => 'a1', 'image_data' => ['url' => 'https://cdn.example/a.jpg', 'width' => 1080, 'height' => 1080]]]]],
            ['id' => 'm_odd', 'created_time' => '2026-11-17T11:00:00+0000', 'from' => ['id' => '556', 'username' => 'zirve'], 'is_unsupported' => true],
        ]],
    ]]]);

    expect(inboxMetaSync($this->instagram, 'message'))->toBe(5);

    expect(InboxItem::query()->pluck('text', 'external_id')->all())->toEqual([
        'm_mention' => '📸 Hikâyende bizi etiketledi',
        'm_reply' => '↩️ Hikâyene cevap verdi: 🔥🔥',
        'm_photo' => "Afiş\n🖼️ Görsel gönderdi",
        'm_odd' => 'Desteklenmeyen mesaj türü',
        'reaction:m_ours:😍' => '😍 Mesaja tepki verdi',
    ]);
    expect(InboxItem::query()->where('external_id', 'm_mention')->sole()->meta['source']['story']['mention']['id'])->toBe('17901')
        ->and(InboxItem::query()->where('external_id', 'reaction:m_ours:😍')->sole()->received_at->toIso8601String())->toBe('2026-11-17T11:04:00+00:00');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), INBOX_IG_ID.'/conversations')
        && str_contains((string) $request['fields'], 'story,shares,reactions,is_unsupported'));
});

test('messenger asks only for the fields the page message has', function () {
    $this->facebook->forceFill(['scopes' => [...$this->facebook->scopes, 'pages_messaging']])->save();
    inboxMetaOverride('GET /v25.0/'.INBOX_FB_PAGE.'/conversations', ['data' => []]);

    inboxMetaSync($this->facebook, 'message');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), INBOX_FB_PAGE.'/conversations')
        && str_contains((string) $request['fields'], 'attachments,shares,sticker')
        && ! str_contains((string) $request['fields'], 'story'));
});
