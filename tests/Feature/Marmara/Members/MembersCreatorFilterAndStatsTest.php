<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));

    $this->admin = User::factory()->create(['name' => 'Yasir', 'timezone' => 'UTC']);
    $this->workspace = Workspace::factory()->create(['account_id' => $this->admin->account_id, 'user_id' => $this->admin->id]);
    $this->workspace->members()->attach($this->admin->id, membershipPivot('admin'));
    $this->admin->update(['current_workspace_id' => $this->workspace->id]);
    $this->admin = $this->admin->fresh();

    $this->member = workspaceMember($this->workspace, 'member', ['name' => 'Behice Kaya', 'timezone' => 'UTC']);
    $this->linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $this->instagram = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram]);
});

function memberPost(object $test, User $author, SocialAccount $channel, array $attributes = [], string $state = 'draft'): Post
{
    return Post::factory()->forAccount($channel)->{$state}()->create([
        'user_id' => $author->id,
        ...$attributes,
    ]);
}

test('the publish page lists the members for the creator filter and filters drafts by creator', function () {
    $mine = memberPost($this, $this->admin, $this->linkedin);
    $hers = memberPost($this, $this->member, $this->linkedin, ['agent_user_id' => $this->admin->id]);

    $this->actingAs($this->admin)
        ->get(route('app.posts.index', ['tab' => 'drafts', 'creators' => [$this->member->id]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.creators', [$this->member->id])
            ->has('filterMembers', 2)
            ->where('filterMembers.0.name', 'Behice Kaya')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 1)
                ->where('posts.data.0.id', $hers->id)
                ->where('posts.data.0.user.name', 'Behice Kaya')
                ->where('posts.data.0.agent_user_id', $this->admin->id)));

    $this->actingAs($this->admin)
        ->get(route('app.posts.index', ['tab' => 'drafts']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.creators', [])
            ->loadDeferredProps(fn ($reload) => $reload->has('posts.data', 2)));

    expect($mine->agent_user_id)->toBeNull();
});

test('the creator filter narrows the sent tab and ignores ids that are not uuids', function () {
    memberPost($this, $this->admin, $this->linkedin, [], 'published');
    $hers = memberPost($this, $this->member, $this->instagram, [], 'published');

    $this->actingAs($this->admin)
        ->get(route('app.posts.index', ['tab' => 'sent', 'creators' => [$this->member->id, 'yasir']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.creators', [$this->member->id])
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 1)
                ->where('posts.data.0.id', $hers->id)));
});

test('the calendar and the board read the creator filter too', function () {
    memberPost($this, $this->admin, $this->linkedin, [], 'scheduled');
    $hers = memberPost($this, $this->member, $this->linkedin, [], 'scheduled');

    $this->actingAs($this->admin)
        ->get(route('app.calendar', ['view' => 'week', 'creators' => [$this->member->id]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.creators', [$this->member->id])
            ->has('filterMembers', 2)
            ->where('posts', fn ($days) => collect($days)->flatten(1)->pluck('id')->all() === [$hers->id]));

    $this->actingAs($this->admin)
        ->get(route('app.posts.board', ['creators' => [$this->member->id]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.creators', [$this->member->id])->has('filterMembers', 2));
});

test('member post counts follow the period, the publish result and the channels', function () {
    $inRange = ['published_at' => '2026-11-10 10:00:00'];

    // Behice: two published (one through WhatsApp, one since deleted on the network), one failed,
    // one scheduled, one waiting for approval, one draft; plus posts outside the period.
    memberPost($this, $this->member, $this->linkedin, [...$inRange, 'agent_user_id' => $this->admin->id], 'published');
    memberPost($this, $this->member, $this->instagram, $inRange, 'deletedOnNetwork');
    memberPost($this, $this->member, $this->instagram, ['scheduled_at' => '2026-11-12 09:00:00'], 'failed');
    memberPost($this, $this->member, $this->linkedin, ['scheduled_at' => '2026-11-25 09:00:00'], 'scheduled');
    memberPost($this, $this->member, $this->linkedin, ['scheduled_at' => '2026-11-26 09:00:00'], 'pendingApproval');
    memberPost($this, $this->member, $this->linkedin, ['created_at' => '2026-11-15 09:00:00']);
    memberPost($this, $this->member, $this->linkedin, ['published_at' => '2026-10-01 10:00:00'], 'published');
    memberPost($this, $this->member, $this->linkedin, ['created_at' => '2026-10-01 09:00:00']);

    // Yasir: one published, one still publishing (not counted as published), and an imported post (left out).
    memberPost($this, $this->admin, $this->linkedin, $inRange, 'published');
    memberPost($this, $this->admin, $this->linkedin, ['scheduled_at' => '2026-11-20 11:00:00'], 'publishing');
    memberPost($this, $this->admin, $this->linkedin, $inRange, 'imported');

    // A post of another workspace never counts.
    Post::factory()->published()->create(['user_id' => $this->member->id, 'published_at' => '2026-11-10 10:00:00']);

    $this->actingAs($this->admin)
        ->getJson(route('marmara.members.posts', ['start' => '2026-11-01', 'end' => '2026-11-30']))
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('start', '2026-11-01')
            ->where('end', '2026-11-30')
            ->has('members', 2)
            ->has('members.0', fn (AssertableJson $row) => $row
                ->where('user.id', $this->member->id)
                ->where('user.name', 'Behice Kaya')
                ->where('published', 2)
                ->where('failed', 1)
                ->where('scheduled', 1)
                ->where('pending_approval', 1)
                ->where('drafts', 1)
                ->where('via_whatsapp', 1)
                ->where('platforms', ['linkedin' => 1, 'instagram' => 1])
                ->etc())
            ->has('members.1', fn (AssertableJson $row) => $row
                ->where('user.id', $this->admin->id)
                ->where('published', 1)
                ->where('scheduled', 1)
                ->where('drafts', 0)
                ->where('via_whatsapp', 0)
                ->etc()));

    $this->actingAs($this->admin)
        ->getJson(route('marmara.members.posts', ['start' => '2026-11-01', 'end' => '2026-11-30', 'channels' => [$this->instagram->id]]))
        ->assertOk()
        ->assertJsonPath('members.0.user.id', $this->member->id)
        ->assertJsonPath('members.0.published', 1)
        ->assertJsonPath('members.0.failed', 1)
        ->assertJsonPath('members.0.drafts', 0)
        ->assertJsonPath('members.0.platforms', ['instagram' => 1])
        ->assertJsonPath('members.1.published', 0);
});

test('member post counts need a workspace member and a valid period', function () {
    $this->actingAs($this->admin)
        ->getJson(route('marmara.members.posts', ['start' => '2026-11-30', 'end' => '2026-11-01']))
        ->assertUnprocessable();

    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider)
        ->getJson(route('marmara.members.posts', ['start' => '2026-11-01', 'end' => '2026-11-30']))
        ->assertForbidden();

    $this->getJson(route('marmara.members.posts', ['start' => '2026-11-01', 'end' => '2026-11-30']))
        ->assertUnauthorized();
});
