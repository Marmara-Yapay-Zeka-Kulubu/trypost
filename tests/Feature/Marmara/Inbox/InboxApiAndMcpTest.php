<?php

declare(strict_types=1);

use App\Marmara\Inbox\Jobs\SyncInboxAccount;
use App\Marmara\Inbox\Mcp\GetInboxItemTool;
use App\Marmara\Inbox\Mcp\ListInboxItemsTool;
use App\Marmara\Inbox\Mcp\ReplyToInboxItemTool;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Models\InboxReply;
use App\Marmara\Inbox\Models\InboxSyncState;
use App\Mcp\Servers\TryPostServer;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00 UTC'));

    config()->set('trypost.platforms.facebook.graph_api', 'https://graph.facebook.com/v25.0');
    config()->set('marmara-inbox.enabled', true);
    config()->set('marmara-inbox.providers.facebook', true);

    ['plain_token' => $token, 'workspace' => $workspace, 'user' => $user] = createApiTestToken();
    $this->workspace = $workspace;
    $this->owner = $user;
    $this->headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    $this->page = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'platform_user_id' => '1122334455',
        'meta' => ['page_id' => '1122334455'],
        'scopes' => ['pages_read_engagement', 'pages_read_user_content', 'pages_manage_engagement', 'pages_messaging'],
        'token_expires_at' => null,
    ]);
    $this->comment = inboxApiItem($this->page, ['external_id' => '777_1', 'text' => 'Katılacağım!', 'received_at' => now()->subHour()]);
    $this->message = inboxApiItem($this->page, [
        'kind' => 'message',
        'external_id' => 'm_1',
        'thread_id' => 't_1',
        'author_external_id' => '555',
        'text' => 'Staj var mı?',
        'received_at' => now()->subHours(30),
        'reply_window_ends_at' => now()->subHours(6),
    ]);

    Http::fake([
        'graph.facebook.com/v25.0/777_1/comments' => Http::response(['id' => '777_9']),
    ]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function inboxApiItem(SocialAccount $account, array $attributes = []): InboxItem
{
    return InboxItem::query()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'provider' => 'facebook',
        'platform' => $account->platform->value,
        'kind' => 'comment',
        'external_id' => 'x',
        'post_external_id' => '1122334455_777',
        'post_excerpt' => 'Kulüp tanıtım günü',
        'author_name' => 'Ali Veli',
        'received_at' => now(),
        'can_reply' => true,
        ...$attributes,
    ]);
}

test('the api lists unread items in the contract shape, newest first', function () {
    $this->getJson('/api/inbox/items?status=unread&limit=1', $this->headers)
        ->assertOk()
        ->assertJsonPath('has_more', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0', [
            'id' => $this->comment->id,
            'platform' => 'facebook',
            'kind' => 'comment',
            'author' => ['name' => 'Ali Veli', 'handle' => null],
            'text' => 'Katılacağım!',
            'permalink' => null,
            'post' => ['id' => '1122334455_777', 'excerpt' => 'Kulüp tanıtım günü'],
            'received_at' => '2026-11-17T11:00:00+00:00',
            'can_reply' => true,
            'reply_window_ends_at' => null,
            'status' => 'unread',
        ]);

    $this->getJson('/api/inbox/items?status=unread', $this->headers)
        ->assertJsonPath('data.1.id', $this->message->id)
        ->assertJsonPath('data.1.post', null)
        ->assertJsonPath('data.1.can_reply', false)
        ->assertJsonPath('data.1.reply_window_ends_at', '2026-11-17T06:00:00+00:00');
});

test('the api shows one item and marks it read', function () {
    $this->getJson("/api/inbox/items/{$this->comment->id}", $this->headers)
        ->assertOk()
        ->assertJsonPath('id', $this->comment->id)
        ->assertJsonPath('status', 'unread')
        ->assertJsonPath('replies', []);

    $this->postJson("/api/inbox/items/{$this->comment->id}/read", [], $this->headers)
        ->assertOk()
        ->assertJson(['id' => $this->comment->id, 'status' => 'read']);

    $this->getJson('/api/inbox/items?status=unread', $this->headers)->assertJsonCount(1, 'data');
});

test('a reply is sent once per request id and replays the same result', function () {
    $first = $this->postJson("/api/inbox/items/{$this->comment->id}/replies", ['text' => 'Bekliyoruz!', 'request_id' => 'echo-42'], $this->headers)
        ->assertCreated()
        ->assertJsonPath('status', 'sent');

    $this->postJson("/api/inbox/items/{$this->comment->id}/replies", ['text' => 'Bekliyoruz!', 'request_id' => 'echo-42'], $this->headers)
        ->assertCreated()
        ->assertExactJson(['id' => $first->json('id'), 'status' => 'sent']);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['message'] === 'Bekliyoruz!');
    expect(InboxReply::query()->sole()->external_id)->toBe('777_9')
        ->and(InboxReply::query()->sole()->user_id)->toBe($this->owner->id);
});

test('a reply after the messaging window answers 409 and a too long one 422', function () {
    $this->postJson("/api/inbox/items/{$this->message->id}/replies", ['text' => 'Geç kaldık', 'request_id' => 'late'], $this->headers)
        ->assertStatus(409)
        ->assertJsonPath('code', 'reply_window_closed');

    $this->postJson("/api/inbox/items/{$this->comment->id}/replies", ['text' => str_repeat('a', 8001), 'request_id' => 'long'], $this->headers)
        ->assertStatus(422)
        ->assertJsonPath('code', 'text_too_long');

    $this->postJson("/api/inbox/items/{$this->comment->id}/replies", ['text' => 'Merhaba'], $this->headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('request_id');

    Http::assertNothingSent();
});

test('items of another workspace are not found', function () {
    $other = SocialAccount::factory()->facebook()->create();
    $foreign = inboxApiItem($other, ['external_id' => 'other']);

    $this->getJson("/api/inbox/items/{$foreign->id}", $this->headers)->assertNotFound();
    $this->postJson("/api/inbox/items/{$foreign->id}/replies", ['text' => 'x', 'request_id' => 'r'], $this->headers)->assertNotFound();
    $this->getJson('/api/inbox/items/not-a-uuid', $this->headers)->assertNotFound();
});

test('the api, page and mcp tools are off with MARMARA_INBOX_ENABLED', function () {
    config()->set('marmara-inbox.enabled', false);

    $this->getJson('/api/inbox/items', $this->headers)->assertNotFound();
    $this->actingAs($this->owner)->get('/inbox')->assertNotFound();
    TryPostServer::actingAs($this->owner)->tools()->assertNotRegistered([ListInboxItemsTool::class, GetInboxItemTool::class, ReplyToInboxItemTool::class]);
});

test('the mcp tools list, show and reply with the same rules', function () {
    TryPostServer::actingAs($this->owner)->tools()->assertRegistered([ListInboxItemsTool::class, GetInboxItemTool::class, ReplyToInboxItemTool::class]);

    TryPostServer::actingAs($this->owner)->tool(ListInboxItemsTool::class, ['kind' => 'comment'])
        ->assertOk()
        ->assertSee($this->comment->id)
        ->assertDontSee($this->message->id);

    TryPostServer::actingAs($this->owner)->tool(GetInboxItemTool::class, ['item_id' => $this->message->id])
        ->assertOk()
        ->assertSee('Staj var mı?');

    TryPostServer::actingAs($this->owner)->tool(ReplyToInboxItemTool::class, ['item_id' => $this->comment->id, 'text' => 'Bekliyoruz!', 'request_id' => 'hermes-1'])
        ->assertOk()
        ->assertSee('"status":"sent"');
    TryPostServer::actingAs($this->owner)->tool(ReplyToInboxItemTool::class, ['item_id' => $this->comment->id, 'text' => 'Bekliyoruz!', 'request_id' => 'hermes-1'])
        ->assertOk()
        ->assertSee('"replayed":true');

    TryPostServer::actingAs($this->owner)->tool(ReplyToInboxItemTool::class, ['item_id' => $this->message->id, 'text' => 'Geç', 'request_id' => 'hermes-2'])
        ->assertHasErrors(['reply_window_closed']);

    Http::assertSentCount(1);
});

test('members whose posts need approval can read the inbox but not reply', function () {
    $member = workspaceMember($this->workspace, 'approval');

    TryPostServer::actingAs($member)->tool(ListInboxItemsTool::class)->assertOk();
    TryPostServer::actingAs($member)->tool(ReplyToInboxItemTool::class, ['item_id' => $this->comment->id, 'text' => 'x', 'request_id' => 'm-1'])
        ->assertHasErrors();

    $this->actingAs($member)
        ->post("/inbox/{$this->comment->id}/replies", ['text' => 'x'])
        ->assertForbidden();
    Http::assertNothingSent();
});

test('the inbox page lists items, opens a thread and archives it', function () {
    $this->actingAs($this->owner)
        ->get('/inbox?item='.$this->comment->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marmara/Inbox')
            ->where('copy.title', 'Inbox')
            ->has('items', 2)
            ->where('selected.id', $this->comment->id)
            ->where('selected.can_hide', true)
            ->where('canReply', true)
            ->where('channels.0.provider', 'facebook')
            ->where('marmaraInbox.unread', 1));

    expect($this->comment->fresh()->read_at)->not->toBeNull();

    $this->actingAs($this->owner)->post("/inbox/{$this->comment->id}/archive")->assertRedirect();
    expect($this->comment->fresh()->archived_at)->not->toBeNull();
});

test('the inbox page speaks turkish', function () {
    $this->owner->forceFill(['locale' => 'tr'])->save();

    $this->actingAs($this->owner)
        ->get('/inbox')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('copy.title', 'Gelen Kutusu'));
});

function inboxFacebookConnectScopes(object $test): array
{
    $location = (string) $test->actingAs($test->owner)->get(route('app.social.facebook.connect'))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return preg_split('/[\s,]+/', (string) ($query['scope'] ?? '')) ?: [];
}

test('the facebook connect asks for the inbox scopes while the provider is on', function () {
    expect(inboxFacebookConnectScopes($this))->toContain('pages_show_list', 'pages_manage_engagement', 'pages_messaging', 'pages_read_user_content');
});

test('the facebook connect keeps upstream scopes while the provider is off', function () {
    config()->set('marmara-inbox.providers.facebook', false);

    expect(inboxFacebookConnectScopes($this))->toContain('pages_show_list')->not->toContain('pages_manage_engagement');
});

test('the scheduled sync queues one job per channel and kind, only when due and enabled', function () {
    $this->artisan('marmara:inbox:sync')->assertSuccessful();

    Queue::assertPushed(SyncInboxAccount::class, 2);
    Queue::assertPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job): bool => $job->account->is($this->page) && $job->provider === 'facebook' && $job->kind === 'message');

    // Just attempted: not due again within the interval.
    InboxSyncState::query()->update(['last_attempt_at' => now()]);
    $this->artisan('marmara:inbox:sync')->assertSuccessful();
    Queue::assertPushed(SyncInboxAccount::class, 2);

    config()->set('marmara-inbox.enabled', false);
    $this->travel(1)->hours();
    $this->artisan('marmara:inbox:sync')->expectsOutputToContain('MARMARA_INBOX_ENABLED is off')->assertSuccessful();
    Queue::assertPushed(SyncInboxAccount::class, 2);
});
