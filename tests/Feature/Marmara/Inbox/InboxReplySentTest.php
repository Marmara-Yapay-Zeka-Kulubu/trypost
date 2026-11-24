<?php

declare(strict_types=1);

use App\Enums\Webhook\EventType;
use App\Jobs\DispatchWebhook;
use App\Marmara\Inbox\Actions\SendInboxReply;
use App\Marmara\Inbox\Mcp\ReplyToInboxItemTool;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Models\InboxReply;
use App\Mcp\Servers\TryPostServer;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00 UTC'));

    config()->set('trypost.platforms.facebook.graph_api', 'https://graph.facebook.com/v25.0');
    config()->set('marmara-inbox.enabled', true);
    config()->set('marmara-inbox.providers.facebook', true);

    // Yasir: the admin whose token Echo and Hermes use.
    ['plain_token' => $token, 'workspace' => $workspace, 'user' => $user] = createApiTestToken();
    $user->forceFill(['name' => 'Yasir'])->save();
    $this->workspace = $workspace;
    $this->owner = $user->fresh();
    $this->headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

    // Behice: a member who asked Hermes on WhatsApp to reply.
    $this->member = workspaceMember($workspace, 'member', ['name' => 'Behice Kaya']);

    $this->page = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'platform_user_id' => '1122334455',
        'meta' => ['page_id' => '1122334455'],
        'scopes' => ['pages_read_engagement', 'pages_read_user_content', 'pages_manage_engagement', 'pages_messaging'],
        'token_expires_at' => null,
    ]);
    $this->comment = InboxItem::query()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $this->page->id,
        'provider' => 'facebook',
        'platform' => $this->page->platform->value,
        'kind' => 'comment',
        'external_id' => '777_1',
        'post_external_id' => '1122334455_777',
        'post_excerpt' => 'Kulüp tanıtım günü',
        'author_name' => 'Ali Veli',
        'text' => 'Katılacağım!',
        'received_at' => now()->subHour(),
        'can_reply' => true,
    ]);

    Webhook::factory()->create(['workspace_id' => $workspace->id, 'events' => [EventType::InboxReplySent->value]]);

    Http::fake([
        'graph.facebook.com/v25.0/777_1/comments' => Http::response(['id' => '777_9']),
    ]);
});

/**
 * The inbox.reply_sent jobs pushed so far.
 *
 * @return list<DispatchWebhook>
 */
function inboxReplySentJobs(): array
{
    return Queue::pushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === EventType::InboxReplySent->value)->values()->all();
}

test('a panel reply fires inbox.reply_sent once with the item, the reply, the panel user and via panel', function () {
    $this->actingAs($this->member)
        ->post("/inbox/{$this->comment->id}/replies", ['text' => 'Bekliyoruz!', 'request_id' => 'panel-1'])
        ->assertRedirect();

    // The same request id again (a double click) sends nothing and announces nothing.
    $this->actingAs($this->member)
        ->post("/inbox/{$this->comment->id}/replies", ['text' => 'Bekliyoruz!', 'request_id' => 'panel-1'])
        ->assertRedirect();

    $reply = InboxReply::query()->sole();
    $jobs = inboxReplySentJobs();

    expect($jobs)->toHaveCount(1)
        ->and($reply->via)->toBe(InboxReply::VIA_PANEL)
        ->and($reply->user_id)->toBe($this->member->id)
        ->and($reply->agent_user_id)->toBeNull()
        ->and($jobs[0]->payload)->toBe([
            'workspace' => ['id' => $this->workspace->id, 'name' => $this->workspace->name],
            'item' => [
                'id' => $this->comment->id,
                'platform' => 'facebook',
                'kind' => 'comment',
                'author' => ['name' => 'Ali Veli', 'handle' => null],
                'text' => 'Katılacağım!',
                'permalink' => null,
                'post' => ['id' => '1122334455_777', 'excerpt' => 'Kulüp tanıtım günü'],
                'received_at' => '2026-11-24T11:00:00+00:00',
                'can_reply' => true,
                'reply_window_ends_at' => null,
            ],
            'reply' => [
                'id' => $reply->id,
                'text' => 'Bekliyoruz!',
                'sent_at' => '2026-11-24T12:00:00+00:00',
                'request_id' => 'panel-1',
            ],
            'sent_by' => ['id' => $this->member->id, 'name' => 'Behice Kaya'],
            'via' => 'panel',
        ]);
    Http::assertSentCount(1);
});

test('an api reply with an echo request id is via echo, any other api reply via api', function () {
    $this->postJson("/api/inbox/items/{$this->comment->id}/replies", ['text' => 'Bekliyoruz!', 'request_id' => 'echo-ib-0123456789abcdef'], $this->headers)
        ->assertCreated()
        ->assertJsonPath('status', 'sent');
    $this->postJson("/api/inbox/items/{$this->comment->id}/replies", ['text' => 'Bekliyoruz!', 'request_id' => 'echo-ib-0123456789abcdef'], $this->headers)
        ->assertCreated();

    expect(inboxReplySentJobs())->toHaveCount(1)
        ->and(inboxReplySentJobs()[0]->payload['via'])->toBe('echo')
        ->and(inboxReplySentJobs()[0]->payload['sent_by'])->toBe(['id' => $this->owner->id, 'name' => 'Yasir'])
        ->and(inboxReplySentJobs()[0]->payload['reply']['request_id'])->toBe('echo-ib-0123456789abcdef');

    $first = InboxReply::query()->sole();
    $this->postJson("/api/inbox/items/{$this->comment->id}/replies", ['text' => 'Tekrar', 'request_id' => 'zapier-7', 'acknowledged_reply_id' => $first->id], $this->headers)
        ->assertCreated();

    expect(inboxReplySentJobs())->toHaveCount(2)
        ->and(inboxReplySentJobs()[1]->payload['via'])->toBe('api')
        ->and(InboxReply::query()->where('request_id', 'zapier-7')->sole()->via)->toBe(InboxReply::VIA_API);
});

test('an mcp reply on behalf of a member is credited to the member and sent by the token owner', function () {
    TryPostServer::actingAs($this->owner)->tool(ReplyToInboxItemTool::class, [
        'item_id' => $this->comment->id,
        'text' => 'Bekliyoruz!',
        'request_id' => 'hermes-1',
        'on_behalf_of_user_id' => $this->member->id,
    ])->assertOk()->assertSee('"status":"sent"');

    $reply = InboxReply::query()->sole();

    expect($reply->user_id)->toBe($this->member->id)
        ->and($reply->agent_user_id)->toBe($this->owner->id)
        ->and($reply->via)->toBe(InboxReply::VIA_AGENT)
        ->and(inboxReplySentJobs())->toHaveCount(1)
        ->and(inboxReplySentJobs()[0]->payload['sent_by'])->toBe(['id' => $this->member->id, 'name' => 'Behice Kaya'])
        ->and(inboxReplySentJobs()[0]->payload['via'])->toBe('agent');

    // Who replied, as the API and the already_replied refusal show it: the member.
    $this->getJson("/api/inbox/items/{$this->comment->id}", $this->headers)
        ->assertJsonPath('replies.0.author.name', 'Behice Kaya');
});

test('an mcp reply without on_behalf_of_user_id is credited to the token owner', function () {
    TryPostServer::actingAs($this->owner)->tool(ReplyToInboxItemTool::class, [
        'item_id' => $this->comment->id,
        'text' => 'Bekliyoruz!',
        'request_id' => 'hermes-2',
        'on_behalf_of_user_id' => null,
    ])->assertOk();

    $reply = InboxReply::query()->sole();

    expect($reply->user_id)->toBe($this->owner->id)
        ->and($reply->agent_user_id)->toBeNull()
        ->and(inboxReplySentJobs()[0]->payload['sent_by'])->toBe(['id' => $this->owner->id, 'name' => 'Yasir'])
        ->and(inboxReplySentJobs()[0]->payload['via'])->toBe('agent');
});

test('an on_behalf_of_user_id that is not a member is refused and nothing is sent', function () {
    $outsider = workspaceOutsider($this->workspace);
    $stranger = User::factory()->create();

    foreach ([$outsider->id, $stranger->id, '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'] as $userId) {
        TryPostServer::actingAs($this->owner)->tool(ReplyToInboxItemTool::class, [
            'item_id' => $this->comment->id,
            'text' => 'x',
            'request_id' => 'hermes-x',
            'on_behalf_of_user_id' => $userId,
        ])->assertHasErrors(['not a member of this workspace']);
    }

    TryPostServer::actingAs($this->owner)->tool(ReplyToInboxItemTool::class, [
        'item_id' => $this->comment->id,
        'text' => 'x',
        'request_id' => 'hermes-y',
        'on_behalf_of_user_id' => 'behice',
    ])->assertHasErrors();

    Http::assertNothingSent();
    expect(InboxReply::query()->count())->toBe(0)
        ->and(inboxReplySentJobs())->toBe([]);
});

test('a reply the network refuses is failed and not announced', function () {
    Http::fake([
        'graph.facebook.com/v25.0/777_1/comments' => Http::response(['error' => ['message' => 'Invalid parameter', 'code' => 100]], 400),
    ]);

    $result = SendInboxReply::execute($this->comment, $this->owner, 'Bekliyoruz!', 'echo-ib-failed', via: InboxReply::VIA_ECHO);

    expect($result['reply']->status)->toBe(InboxReply::STATUS_FAILED)
        ->and(inboxReplySentJobs())->toBe([]);
});

test('a reply still sending is not announced, and replaying it announces nothing', function () {
    $sending = InboxReply::query()->create([
        'inbox_item_id' => $this->comment->id,
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $this->page->id,
        'user_id' => $this->owner->id,
        'via' => InboxReply::VIA_ECHO,
        'request_id' => 'echo-ib-pending',
        'text' => 'Bekliyoruz!',
        'status' => InboxReply::STATUS_SENDING,
    ]);

    $result = SendInboxReply::execute($this->comment, $this->owner, 'Bekliyoruz!', 'echo-ib-pending', via: InboxReply::VIA_ECHO);

    expect($result['replayed'])->toBeTrue()
        ->and($result['reply']->is($sending))->toBeTrue()
        ->and($result['reply']->status)->toBe(InboxReply::STATUS_SENDING)
        ->and(inboxReplySentJobs())->toBe([]);
    Http::assertNothingSent();
});

test('a sent reply replayed later is not announced again', function () {
    SendInboxReply::execute($this->comment, $this->owner, 'Bekliyoruz!', 'echo-ib-once', via: InboxReply::VIA_ECHO);
    $this->travel(10)->minutes();
    SendInboxReply::execute($this->comment->fresh(), $this->owner, 'Bekliyoruz!', 'echo-ib-once', via: InboxReply::VIA_ECHO);

    expect(inboxReplySentJobs())->toHaveCount(1);
    Http::assertSentCount(1);
});

test('only webhooks subscribed to inbox.reply_sent get it', function () {
    Webhook::query()->sole()->update(['events' => [EventType::InboxItemReceived->value]]);

    SendInboxReply::execute($this->comment, $this->owner, 'Bekliyoruz!', 'echo-ib-unsubscribed', via: InboxReply::VIA_ECHO);

    expect(InboxReply::query()->sole()->status)->toBe(InboxReply::STATUS_SENT)
        ->and(inboxReplySentJobs())->toBe([]);
});
