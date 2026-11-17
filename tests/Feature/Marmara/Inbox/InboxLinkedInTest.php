<?php

declare(strict_types=1);

use App\Enums\Webhook\EventType;
use App\Jobs\DispatchWebhook;
use App\Marmara\Inbox\Actions\SendInboxReply;
use App\Marmara\Inbox\InboxProviders;
use App\Marmara\Inbox\InboxSync;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Models\InboxReply;
use App\Marmara\Inbox\Models\InboxSyncState;
use App\Marmara\Inbox\Providers\LinkedInInboxProvider;
use App\Marmara\LinkedIn\LinkedInCallBudget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const INBOX_LI_ORG = '104857600';
const INBOX_LI_SHARE = 'urn:li:share:7390000000000000001';
const INBOX_LI_COMMENT = 'urn:li:comment:(urn:li:activity:7390000000000000111,7390000000000000201)';
const INBOX_LI_OWN_REPLY = 'urn:li:comment:(urn:li:activity:7390000000000000111,7390000000000000202)';

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00 UTC'));

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->page = SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => INBOX_LI_ORG,
        'display_name' => 'Marmara Yapay Zeka Kulübü',
        'meta' => ['organization_id' => INBOX_LI_ORG, 'admin_user_id' => 'member-1'],
        'scopes' => ['r_basicprofile', 'w_organization_social', 'r_organization_social', 'rw_organization_admin', 'r_organization_social_feed', 'w_organization_social_feed'],
        'token_expires_at' => now()->addDays(50),
    ]);

    config()->set('marmara-zernio.enabled', false);
    config()->set('marmara-linkedin.api', 'https://api.linkedin.com');
    config()->set('marmara-linkedin.daily_call_budget', 80);
    config()->set('marmara-inbox.enabled', true);
    config()->set('marmara-inbox.providers.linkedin', true);
    config()->set('marmara-inbox.linkedin.daily_calls', 30);
    config()->set('marmara-inbox.linkedin.posts_per_run', 3);
    config()->set('marmara-inbox.notify_max_age_hours', 24);
});

function inboxLiMs(string $time): int
{
    return CarbonImmutable::parse($time)->getTimestampMs();
}

/**
 * One fake for the whole test: the closure reads the latest call's answers,
 * so a test can change them (a second Http::fake would not win: the first
 * matching stub answers).
 *
 * @param  list<array<string, mixed>>  $comments
 * @param  array<string, string>  $commentsHeaders
 */
function inboxLiFake(array $comments, int $commentsStatus = 200, array $commentsHeaders = []): void
{
    app()->instance('marmara-inbox-test.linkedin', [$comments, $commentsStatus, $commentsHeaders]);

    Http::fake(function (Request $request) {
        [$comments, $status, $headers] = app('marmara-inbox-test.linkedin');

        if (str_contains($request->url(), 'api.linkedin.com/rest/posts')) {
            return Http::response([
                'elements' => [
                    ['id' => INBOX_LI_SHARE, 'publishedAt' => inboxLiMs('2026-11-15 09:00 UTC'), 'commentary' => 'Yapay zeka atölyesi bu cuma!'],
                    // Older than the 14-day window: never polled.
                    ['id' => 'urn:li:share:7380000000000000009', 'publishedAt' => inboxLiMs('2026-09-01 09:00 UTC')],
                ],
                'paging' => ['links' => []],
            ]);
        }

        if (str_contains($request->url(), 'api.linkedin.com/rest/socialActions/')) {
            return $request->method() === 'POST'
                ? Http::response([], 201, ['x-restli-id' => '7390000000000000301'])
                : Http::response(['elements' => $comments], $status, $headers);
        }

        return null;
    });
}

function inboxLiComments(): array
{
    return [
        [
            'actor' => 'urn:li:person:A8xe03Qt10',
            'commentUrn' => INBOX_LI_COMMENT,
            'id' => '7390000000000000201',
            'created' => ['actor' => 'urn:li:person:A8xe03Qt10', 'time' => inboxLiMs('2026-11-17 10:00 UTC')],
            'message' => ['text' => 'Harika etkinlik, kayıt linki var mı?', 'attributes' => []],
            'object' => 'urn:li:activity:7390000000000000111',
        ],
        [
            // Our own reply as the page: never an inbox item.
            'actor' => 'urn:li:organization:'.INBOX_LI_ORG,
            'commentUrn' => INBOX_LI_OWN_REPLY,
            'id' => '7390000000000000202',
            'created' => ['actor' => 'urn:li:person:member-1', 'time' => inboxLiMs('2026-11-17 10:30 UTC')],
            'message' => ['text' => 'Evet, profilimizde!'],
            'object' => 'urn:li:activity:7390000000000000111',
            'parentComment' => INBOX_LI_COMMENT,
        ],
    ];
}

function inboxLiSync(SocialAccount $page): int
{
    $provider = app(InboxProviders::class)->for($page);

    expect($provider)->toBeInstanceOf(LinkedInInboxProvider::class);

    return app(InboxSync::class)->run($page, $provider, InboxItem::KIND_COMMENT);
}

test('linkedin page comments are mapped, our own reply is skipped and the webhook fires once', function () {
    Webhook::factory()->create(['workspace_id' => $this->workspace->id, 'events' => [EventType::InboxItemReceived->value]]);
    inboxLiFake(inboxLiComments());

    expect(inboxLiSync($this->page))->toBe(1);

    $item = InboxItem::query()->sole();
    expect($item->provider)->toBe('linkedin')
        ->and($item->platform)->toBe('linkedin-page')
        ->and($item->kind)->toBe('comment')
        ->and($item->external_id)->toBe(INBOX_LI_COMMENT)
        ->and($item->thread_id)->toBe(INBOX_LI_COMMENT)
        ->and($item->post_external_id)->toBe(INBOX_LI_SHARE)
        ->and($item->post_excerpt)->toBe('Yapay zeka atölyesi bu cuma!')
        ->and($item->author_external_id)->toBe('urn:li:person:A8xe03Qt10')
        ->and($item->author_name)->toBeNull()
        ->and($item->text)->toBe('Harika etkinlik, kayıt linki var mı?')
        ->and($item->received_at->toIso8601String())->toBe('2026-11-17T10:00:00+00:00')
        ->and($item->can_reply)->toBeTrue()
        ->and($item->reply_window_ends_at)->toBeNull()
        ->and($item->permalink)->toBe('https://www.linkedin.com/feed/update/'.INBOX_LI_SHARE.'?commentUrn='.rawurlencode(INBOX_LI_COMMENT));

    // Only the post inside the window was read, with the versioned headers.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/rest/socialActions/'.rawurlencode(INBOX_LI_SHARE).'/comments')
        && $request->hasHeader('LinkedIn-Version')
        && $request->header('X-Restli-Protocol-Version')[0] === '2.0.0');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'urn%3Ali%3Ashare%3A7380000000000000009'));

    // The same comment read again is not stored or announced twice; the post list is reused.
    expect(inboxLiSync($this->page))->toBe(0)
        ->and(InboxItem::query()->count())->toBe(1);

    Queue::assertPushed(DispatchWebhook::class, 1);
    Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === 'inbox.item_received'
        && $job->payload === ['item' => [
            'id' => $item->id,
            'platform' => 'linkedin-page',
            'kind' => 'comment',
            'author' => ['name' => null, 'handle' => null],
            'text' => 'Harika etkinlik, kayıt linki var mı?',
            'permalink' => $item->permalink,
            'post' => ['id' => INBOX_LI_SHARE, 'excerpt' => 'Yapay zeka atölyesi bu cuma!'],
            'received_at' => '2026-11-17T10:00:00+00:00',
            'can_reply' => true,
            'reply_window_ends_at' => null,
        ]]);
    Http::assertSentCount(3);

    $state = InboxSyncState::query()->sole();
    expect($state->status)->toBe(InboxSyncState::STATUS_OK)
        ->and($state->cursor['posts'])->toHaveCount(1)
        ->and($state->cursor['listed_at'])->toBe('2026-11-17T12:00:00+00:00');
});

test('the inbox takes its calls from the linkedin module budget', function () {
    inboxLiFake(inboxLiComments());

    inboxLiSync($this->page);

    // One posts list + one comments read, counted in both budgets.
    expect(app(LinkedInCallBudget::class)->used())->toBe(2)
        ->and(LinkedInInboxProvider::inboxCallsUsedToday())->toBe(2);
});

test('a used up shared budget pauses the inbox until the next utc day', function () {
    config()->set('marmara-linkedin.daily_call_budget', 1);
    inboxLiFake(inboxLiComments());

    inboxLiSync($this->page);

    $state = InboxSyncState::query()->sole();
    expect($state->status)->toBe(InboxSyncState::STATUS_RATE_LIMITED)
        ->and($state->blocked_until->toIso8601String())->toBe('2026-11-18T00:05:00+00:00')
        ->and(InboxItem::query()->count())->toBe(0)
        // The refused comments call did not keep an inbox unit.
        ->and(LinkedInInboxProvider::inboxCallsUsedToday())->toBe(1);

    // While blocked the sync makes no call at all.
    Http::fake();
    expect(inboxLiSync($this->page))->toBe(0);
    Http::assertNothingSent();
});

test('the inbox share stops before the shared budget does', function () {
    config()->set('marmara-inbox.linkedin.daily_calls', 1);
    inboxLiFake(inboxLiComments());

    inboxLiSync($this->page);

    expect(InboxSyncState::query()->sole()->status)->toBe(InboxSyncState::STATUS_RATE_LIMITED)
        ->and(app(LinkedInCallBudget::class)->used())->toBe(1);
});

test('a linkedin 429 backs off the inbox and the analytics reads alike', function () {
    inboxLiFake([], 429, ['Retry-After' => '600']);

    inboxLiSync($this->page);

    $state = InboxSyncState::query()->sole();
    expect($state->status)->toBe(InboxSyncState::STATUS_RATE_LIMITED)
        ->and($state->blocked_until->toIso8601String())->toBe('2026-11-17T12:10:00+00:00')
        ->and(app(LinkedInCallBudget::class)->blockedUntil()?->toIso8601String())->toBe('2026-11-17T12:10:00+00:00');
});

test('a page without r_organization_social_feed reports the permission it needs and calls nothing', function () {
    $this->page->update(['scopes' => ['r_basicprofile', 'w_organization_social', 'r_organization_social', 'rw_organization_admin']]);
    Http::fake();

    inboxLiSync($this->page);

    $state = InboxSyncState::query()->sole();
    expect($state->status)->toBe(InboxSyncState::STATUS_NEEDS_PERMISSION)
        ->and($state->status_detail)->toContain('r_organization_social_feed');
    Http::assertNothingSent();
});

test('linkedin page dms are reported as unavailable', function () {
    $provider = app(InboxProviders::class)->for($this->page);

    expect(array_keys($provider->capabilities($this->page)))->toBe(['comment'])
        ->and(app(InboxSync::class)->run($this->page, $provider, InboxItem::KIND_MESSAGE))->toBe(0)
        ->and(InboxSyncState::query()->sole()->status)->toBe(InboxSyncState::STATUS_UNSUPPORTED);
});

test('a reply goes out as the organization and is never read back as an item', function () {
    inboxLiFake(inboxLiComments());
    inboxLiSync($this->page);
    $item = InboxItem::query()->sole();

    $result = SendInboxReply::execute($item, $this->user, 'Kayıt linki profilimizde.', 'echo-1');

    expect($result['reply']->status)->toBe(InboxReply::STATUS_SENT)
        ->and($result['reply']->external_id)->toBe('urn:li:comment:(urn:li:activity:7390000000000000111,7390000000000000301)');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/rest/socialActions/'.rawurlencode(INBOX_LI_COMMENT).'/comments')
        && $request['actor'] === 'urn:li:organization:'.INBOX_LI_ORG
        && $request['object'] === INBOX_LI_SHARE
        && $request['parentComment'] === INBOX_LI_COMMENT
        && $request['message'] === ['text' => 'Kayıt linki profilimizde.']);

    // Read back from LinkedIn (even under a person actor), it is ours.
    inboxLiFake([[
        'actor' => 'urn:li:person:member-1',
        'commentUrn' => 'urn:li:comment:(urn:li:activity:7390000000000000111,7390000000000000301)',
        'created' => ['time' => inboxLiMs('2026-11-17 12:00 UTC')],
        'message' => ['text' => 'Kayıt linki profilimizde.'],
        'object' => 'urn:li:activity:7390000000000000111',
    ]]);
    InboxSyncState::query()->update(['last_attempt_at' => null]);

    expect(inboxLiSync($this->page))->toBe(0);
});

test('without w_organization_social_feed the items cannot be answered', function () {
    $this->page->update(['scopes' => ['r_organization_social', 'r_organization_social_feed']]);
    inboxLiFake(inboxLiComments());

    inboxLiSync($this->page);

    expect(InboxItem::query()->sole()->can_reply)->toBeFalse()
        ->and(app(InboxProviders::class)->for($this->page)->missingReplyPermission($this->page, 'comment'))->toContain('w_organization_social_feed');
});

test('the linkedin provider stays off without its flag or for a zernio mapped page', function () {
    config()->set('marmara-inbox.providers.linkedin', false);
    expect(app(InboxProviders::class)->for($this->page))->toBeNull();

    config()->set('marmara-inbox.providers.linkedin', true);
    config()->set('marmara-zernio.enabled', true);
    config()->set('marmara-zernio.accounts', ['linkedin-page' => [$this->page->id => '7b1c2d3e4f5a6b7c8d9e0f1a']]);
    expect(app(InboxProviders::class)->for($this->page))->toBeNull();
});
