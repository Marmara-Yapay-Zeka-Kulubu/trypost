<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use App\Jobs\DispatchWebhook;
use App\Marmara\Designs\DesignPayload;
use App\Marmara\Designs\Jobs\SyncDesignRevisions;
use App\Marmara\Designs\Mcp\RenameDesignTool;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignActivity;
use App\Marmara\Designs\Sync\DesignSync;
use App\Mcp\Servers\TryPostServer;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

beforeEach(function () {
    Queue::fake([SyncDesignRevisions::class, DispatchWebhook::class]);
    ['workspace' => $this->workspace, 'user' => $this->owner, 'plain_token' => $token] = createApiTestToken();
    $this->headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    designsConfigure($this->workspace);
    $this->design = designsMake($this->workspace, ['drive_file_id' => 'filePoster', 'name' => 'IMG_2041.png', 'title' => 'IMG_2041']);
    $this->member = workspaceMember($this->workspace, 'approval', ['name' => 'Behice Kaya']);
});

test('a member renames a design on the page: the title shows everywhere and the history says who changed it', function () {
    $this->actingAs($this->member)
        ->patch("/designs/{$this->design->id}/title", ['title' => '  Bahar afişi  '])
        ->assertRedirect();

    $design = $this->design->fresh();
    expect($design->custom_title)->toBe('Bahar afişi')
        ->and($design->title)->toBe('IMG_2041')
        ->and($design->name)->toBe('IMG_2041.png')
        ->and($design->displayTitle())->toBe('Bahar afişi');

    $activity = DesignActivity::query()->where('design_id', $design->id)->where('type', 'title_changed')->sole();
    expect($activity->user_id)->toBe($this->member->id)
        ->and($activity->data)->toEqual(['from' => 'IMG_2041', 'to' => 'Bahar afişi']);

    $this->actingAs($this->member)
        ->get("/designs?design={$design->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('designs.0.title', 'Bahar afişi')
            ->where('selected.title', 'Bahar afişi')
            ->where('selected.drive_title', 'IMG_2041')
            ->where('selected.custom_title', 'Bahar afişi')
            ->where('selected.name', 'IMG_2041.png')
            ->where('selected.can.rename', true)
            ->where('selected.activity.0.type', 'title_changed')
            ->where('urls.title', route('marmara.designs.title', ['design' => '__ID__'])));

    $this->getJson("/api/designs/{$design->id}", $this->headers)
        ->assertOk()
        ->assertJsonPath('title', 'Bahar afişi')
        ->assertJsonPath('drive_title', 'IMG_2041')
        ->assertJsonPath('custom_title', 'Bahar afişi');
    $this->getJson('/api/designs', $this->headers)->assertOk()->assertJsonPath('data.0.title', 'Bahar afişi');

    expect(DesignPayload::item($design->fresh(), signedThumbnails: true)['title'])->toBe('Bahar afişi');
});

test('the same title again changes nothing and writes no history', function () {
    $this->actingAs($this->member)->patch("/designs/{$this->design->id}/title", ['title' => 'IMG_2041'])->assertRedirect();
    expect($this->design->fresh()->custom_title)->toBeNull();

    $this->actingAs($this->member)->patch("/designs/{$this->design->id}/title", ['title' => 'Bahar afişi'])->assertRedirect();
    $this->actingAs($this->member)->patch("/designs/{$this->design->id}/title", ['title' => 'Bahar afişi'])->assertRedirect();

    expect(DesignActivity::query()->where('type', 'title_changed')->count())->toBe(1);
});

test('an empty title goes back to the drive name', function () {
    $this->design->forceFill(['custom_title' => 'Bahar afişi'])->save();

    $this->actingAs($this->member)->patch("/designs/{$this->design->id}/title", ['title' => ''])->assertRedirect();

    $design = $this->design->fresh();
    expect($design->custom_title)->toBeNull()
        ->and($design->displayTitle())->toBe('IMG_2041')
        ->and(DesignActivity::query()->where('type', 'title_changed')->sole()->data)->toEqual(['from' => 'Bahar afişi', 'to' => 'IMG_2041', 'reset' => true]);

    $this->design->forceFill(['custom_title' => 'Bahar afişi'])->save();
    $this->patchJson("/api/designs/{$this->design->id}/title", ['title' => '   '], $this->headers)
        ->assertOk()
        ->assertJsonPath('title', 'IMG_2041')
        ->assertJsonPath('custom_title', null);
});

test('the title is validated: present, a string, at most 255 characters', function () {
    $this->actingAs($this->member)->patch("/designs/{$this->design->id}/title", [])->assertSessionHasErrors('title');
    $this->actingAs($this->member)->patch("/designs/{$this->design->id}/title", ['title' => str_repeat('a', 256)])->assertSessionHasErrors('title');
    $this->patchJson("/api/designs/{$this->design->id}/title", ['title' => ['x']], $this->headers)->assertUnprocessable();

    expect($this->design->fresh()->custom_title)->toBeNull();
});

test('the rest api renames for the key\'s user and answers the design', function () {
    $this->patchJson("/api/designs/{$this->design->id}/title", ['title' => 'Bahar afişi'], $this->headers)
        ->assertOk()
        ->assertJsonPath('id', $this->design->id)
        ->assertJsonPath('title', 'Bahar afişi')
        ->assertJsonPath('name', 'IMG_2041.png');

    expect(DesignActivity::query()->where('type', 'title_changed')->sole()->user_id)->toBe($this->owner->id);
});

test('a sync keeps the custom title and still follows a rename in drive', function () {
    designsFakeDrive([DESIGNS_ROOT => [designsDriveFile('filePoster', 'IMG_2041.png', DESIGNS_ROOT)]]);
    app(DesignSync::class)->run();

    $this->actingAs($this->member)->patch("/designs/{$this->design->id}/title", ['title' => 'Bahar afişi'])->assertRedirect();

    designsFakeDrive([DESIGNS_ROOT => [designsDriveFile('filePoster', 'IMG_2041.png', DESIGNS_ROOT)]], changes: [
        'token-100' => [
            'newStartPageToken' => 'token-200',
            'changes' => [
                ['kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'filePoster', 'removed' => false,
                    'file' => designsDriveFile('filePoster', 'Afiş son hali.png', DESIGNS_ROOT)],
            ],
        ],
    ]);
    app(DesignSync::class)->run();

    $design = Design::query()->where('drive_file_id', 'filePoster')->sole();
    expect($design->custom_title)->toBe('Bahar afişi')
        ->and($design->title)->toBe('Afiş son hali')
        ->and($design->name)->toBe('Afiş son hali.png')
        ->and($design->displayTitle())->toBe('Bahar afişi');

    // A full walk keeps it too.
    designsFakeDrive([DESIGNS_ROOT => [designsDriveFile('filePoster', 'Afiş son hali.png', DESIGNS_ROOT)]]);
    app(DesignSync::class)->run(full: true);
    expect($design->fresh()->custom_title)->toBe('Bahar afişi');

    // Cleared: the new Drive name shows.
    $this->actingAs($this->member)->patch("/designs/{$design->id}/title", ['title' => ''])->assertRedirect();
    expect($design->fresh()->displayTitle())->toBe('Afiş son hali');
});

test('search matches the custom title as well as the drive name, and the title sort uses the shown title', function () {
    $other = designsMake($this->workspace, ['name' => 'Logo.png', 'title' => 'Logo']);
    $this->design->forceFill(['custom_title' => 'Zümrüt bahar afişi'])->save();

    $ids = fn (array $query): array => collect($this->getJson('/api/designs?'.http_build_query($query), $this->headers)->assertOk()->json('data'))->pluck('id')->all();

    expect($ids(['q' => 'zümrüt']))->toBe([$this->design->id])
        ->and($ids(['q' => 'img']))->toBe([$this->design->id])
        ->and($ids(['q' => 'logo']))->toBe([$other->id])
        ->and($ids(['sort' => 'title']))->toBe([$other->id, $this->design->id]);
});

test('without the right the page and the api answer 403; outsiders and other workspaces get 404', function () {
    Gate::before(fn ($user, string $ability): ?bool => $ability === 'rename' ? false : null);

    $this->actingAs($this->member)->patch("/designs/{$this->design->id}/title", ['title' => 'x'])->assertForbidden();
    $this->patchJson("/api/designs/{$this->design->id}/title", ['title' => 'x'], $this->headers)->assertForbidden();

    $outsider = workspaceOutsider($this->workspace);
    $this->actingAs($outsider)->patch("/designs/{$this->design->id}/title", ['title' => 'x'])->assertNotFound();

    ['user' => $stranger, 'plain_token' => $strangerToken] = createApiTestToken();
    $this->actingAs($stranger)->patch("/designs/{$this->design->id}/title", ['title' => 'x'])->assertNotFound();
    // The web session above would otherwise stand in for the API key.
    $this->app['auth']->forgetGuards();
    $this->patchJson("/api/designs/{$this->design->id}/title", ['title' => 'x'], ['Authorization' => 'Bearer '.$strangerToken, 'Accept' => 'application/json'])->assertNotFound();

    ['workspace' => $otherWorkspace] = createApiTestToken();
    $foreign = designsMake($otherWorkspace);
    $this->actingAs($this->owner)->patch("/designs/{$foreign->id}/title", ['title' => 'x'])->assertNotFound();

    expect($this->design->fresh()->custom_title)->toBeNull()
        ->and($foreign->fresh()->custom_title)->toBeNull();
});

test('the mcp tool is registered with the other designs tools and is a write tool', function () {
    TryPostServer::actingAs($this->owner)->tools()->assertRegistered([RenameDesignTool::class]);

    $readOnly = (new ReflectionClass(RenameDesignTool::class))->getAttributes(IsReadOnly::class)[0]->newInstance();
    expect($readOnly->value)->toBeFalse()
        ->and((new RenameDesignTool)->name())->toBe('rename-design-tool');

    config()->set('marmara-designs.enabled', false);
    TryPostServer::actingAs($this->owner)->tools()->assertNotRegistered([RenameDesignTool::class]);
});

test('the mcp tool renames, credits the member who asked and answers the shown title and the drive name', function () {
    TryPostServer::actingAs($this->owner)->tool(RenameDesignTool::class, [
        'design_id' => $this->design->id,
        'title' => 'Bahar afişi',
        'current_title' => 'IMG_2041',
        'on_behalf_of_user_id' => $this->member->id,
    ])->assertOk()->assertStructuredContent(function (AssertableJson $json) {
        $json->where('id', $this->design->id)
            ->where('title', 'Bahar afişi')
            ->where('drive_name', 'IMG_2041.png')
            ->where('url', route('marmara.designs.index', ['design' => $this->design->id]));
    });

    expect($this->design->fresh()->custom_title)->toBe('Bahar afişi')
        ->and(DesignActivity::query()->where('type', 'title_changed')->sole()->user_id)->toBe($this->member->id);
});

test('the mcp tool without on_behalf_of_user_id credits the token user, and an empty title resets', function () {
    $this->design->forceFill(['custom_title' => 'Bahar afişi'])->save();

    TryPostServer::actingAs($this->owner)->tool(RenameDesignTool::class, ['design_id' => $this->design->id, 'title' => ''])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('title', 'IMG_2041')->etc());

    expect($this->design->fresh()->custom_title)->toBeNull()
        ->and(DesignActivity::query()->where('type', 'title_changed')->sole())
        ->user_id->toBe($this->owner->id)
        ->data->toEqual(['from' => 'Bahar afişi', 'to' => 'IMG_2041', 'reset' => true]);
});

test('the mcp tool refuses a non-member on_behalf_of_user_id, a wrong current_title and an unknown design', function () {
    $outsider = workspaceOutsider($this->workspace);
    $stranger = User::factory()->create();

    foreach ([$outsider->id, $stranger->id, '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'] as $userId) {
        TryPostServer::actingAs($this->owner)->tool(RenameDesignTool::class, [
            'design_id' => $this->design->id,
            'title' => 'Bahar afişi',
            'on_behalf_of_user_id' => $userId,
        ])->assertHasErrors(['not a member of this workspace']);
    }

    TryPostServer::actingAs($this->owner)->tool(RenameDesignTool::class, ['design_id' => $this->design->id, 'title' => 'Bahar afişi', 'current_title' => 'Logo'])
        ->assertHasErrors(['Nothing was changed']);

    ['workspace' => $otherWorkspace] = createApiTestToken();
    $foreign = designsMake($otherWorkspace);
    TryPostServer::actingAs($this->owner)->tool(RenameDesignTool::class, ['design_id' => $foreign->id, 'title' => 'x'])
        ->assertHasErrors(['Design not found.']);

    expect($this->design->fresh()->custom_title)->toBeNull()
        ->and($foreign->fresh()->custom_title)->toBeNull()
        ->and(DesignActivity::query()->where('type', 'title_changed')->count())->toBe(0);
});

test('a post made from a renamed design gets the shown title as its file name', function () {
    Storage::fake();
    $channel = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    $this->design->forceFill(['mime_type' => 'image/jpeg', 'name' => 'IMG_2041.jpg', 'custom_title' => 'Bahar afişi'])->save();
    designsFakeDrive(content: ['filePoster' => designsJpeg(1080, 1350)]);

    $this->actingAs($this->owner)->post("/designs/{$this->design->id}/posts", ['social_account_ids' => [$channel->id]])->assertRedirect();

    $post = Post::query()->where('workspace_id', $this->workspace->id)->sole();
    expect(Media::query()->where('post_id', $post->id)->sole()->original_filename)->toBe('Bahar afişi.jpg');
});
