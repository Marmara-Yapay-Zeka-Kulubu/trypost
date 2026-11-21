<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use App\Http\Middleware\App\HandleInertiaRequests;
use App\Marmara\Designs\Http\DesignsPageController;
use App\Marmara\Designs\Models\DesignComment;
use App\Marmara\Designs\Models\DesignRevision;
use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    ['workspace' => $this->workspace, 'user' => $this->owner] = createApiTestToken();
    designsConfigure($this->workspace);
    $this->channel = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    $this->design = designsMake($this->workspace, ['title' => 'Bahar afişi', 'folder_path' => 'Bahar Şenliği', 'head_revision_id' => 'r2']);
});

test('the page lists the gallery and opens a design with its versions, comments and permissions', function () {
    DesignRevision::query()->create(['design_id' => $this->design->id, 'revision_id' => 'r1', 'mime_type' => 'image/png', 'modified_at' => now()->subDays(3), 'modifier_name' => 'Elif', 'keep_forever' => true]);
    DesignRevision::query()->create(['design_id' => $this->design->id, 'revision_id' => 'r2', 'mime_type' => 'image/png', 'modified_at' => now()->subDay(), 'keep_forever' => true]);
    DesignComment::query()->create(['design_id' => $this->design->id, 'user_id' => $this->owner->id, 'body' => 'Güzel']);

    $this->actingAs($this->owner)
        ->get("/designs?design={$this->design->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marmara/Designs')
            ->where('copy.title', 'Designs')
            ->has('designs', 1)
            ->where('designs.0.id', $this->design->id)
            ->where('designs.0.folder', 'Bahar Şenliği')
            ->where('selected.id', $this->design->id)
            ->has('selected.versions', 2)
            ->where('selected.versions.0.label', 'v1')
            ->where('selected.versions.0.revision_id', 'r1')
            ->where('selected.versions.1.label', 'v2')
            ->where('selected.versions.1.is_current', true)
            ->where('selected.comments_list.0.mine', true)
            ->where('selected.can.statuses', ['draft', 'in_review', 'approved', 'used', 'archived'])
            ->where('selected.can.create_post', true)
            ->where('postChannels.0.id', $this->channel->id)
            ->where('filterOptions.folders', ['Bahar Şenliği'])
            ->where('marmaraDesigns.href', route('marmara.designs.index')));
});

test('a member whose posts need approval sees only the statuses they may choose', function () {
    $this->actingAs(workspaceMember($this->workspace, 'approval'))
        ->get("/designs?design={$this->design->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selected.can.statuses', ['draft', 'in_review', 'archived'])
            ->where('permissions.canApprove', false)
            ->where('permissions.canManageDrive', false));
});

test('the page speaks turkish', function () {
    $this->owner->forceFill(['locale' => 'tr'])->save();

    $this->actingAs($this->owner)
        ->get('/designs')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('copy.title', 'Tasarımlar')
            ->where('copy.statuses.approved', 'Onaylandı')
            ->where('copy.post.create', 'Gönderi oluştur')
            ->where('marmaraDesigns.label', 'Tasarımlar'));
});

test('no page prop shares a name with a shared prop, so the sidebar channels survive', function () {
    $response = $this->actingAs($this->owner)->get("/designs?design={$this->design->id}")->assertOk();

    $request = Request::create('/designs');
    $request->setUserResolver(fn () => $this->owner);
    $request->setLaravelSession(app('session.store'));
    $middleware = app(HandleInertiaRequests::class);
    $shared = [...array_keys($middleware->share($request)), ...array_keys($middleware->shareOnce($request)), ...array_keys(Inertia::getShared())];

    expect(array_values(array_intersect(DesignsPageController::PAGE_PROPS, $shared)))->toBe([])
        ->and($shared)->toContain('channels');

    // PAGE_PROPS is the page's complete own list: everything else came from the shared props.
    $props = array_keys($response->viewData('page')['props']);
    expect(array_values(array_diff($props, $shared, ['errors'])))->toEqualCanonicalizing(DesignsPageController::PAGE_PROPS);

    // And the shared sidebar channels keep their own shape (not the post dialog's channel list).
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->has('channels', 1, fn (AssertableInertia $channel) => $channel
            ->where('id', $this->channel->id)
            ->has('display_label')
            ->has('scheduled_posts_count')
            ->etc())
        ->has('postChannels', 1));
});

test('without the module the sidebar has no designs entry', function () {
    $this->actingAs($this->owner);
    $share = Inertia::getShared('marmaraDesigns');

    expect($share()['href'])->toBe(route('marmara.designs.index'));

    config()->set('marmara-designs.enabled', false);
    expect($share())->toBeNull();
});
