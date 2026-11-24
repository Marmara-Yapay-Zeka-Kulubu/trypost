<?php

declare(strict_types=1);

use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishPost;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    Storage::fake();
    Queue::fake([PublishPost::class]);

    // Yasir: the admin whose token Hermes / Echo uses.
    $this->agent = User::factory()->create(['name' => 'Yasir']);
    $this->workspace = Workspace::factory()->create(['account_id' => $this->agent->account_id, 'user_id' => $this->agent->id]);
    $this->workspace->members()->attach($this->agent->id, membershipPivot('admin'));
    $this->agent->update(['current_workspace_id' => $this->workspace->id]);
    $this->agent = $this->agent->fresh();

    $this->linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $this->x = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X]);

    // Behice: the WhatsApp sender, a member who needs approval in the web app.
    $this->member = workspaceMember($this->workspace, 'approval', ['name' => 'Behice Kaya']);
});

test('create-post-tool credits the post to the member given as on_behalf_of_user_id', function () {
    TryPostServer::actingAs($this->agent)->tool(CreatePostTool::class, [
        'social_account_id' => $this->linkedin->id,
        'content' => 'Bahar şenliği duyurusu',
        'on_behalf_of_user_id' => $this->member->id,
    ])->assertOk()->assertStructuredContent(function (AssertableJson $json) {
        $json->where('author.id', $this->member->id)->etc();
    });

    $post = Post::query()->sole();

    expect($post->user_id)->toBe($this->member->id)
        ->and($post->agent_user_id)->toBe($this->agent->id)
        ->and($post->created_via)->toBe(CreatedVia::Mcp)
        ->and($post->status)->toBe(PostStatus::Draft);
});

test('permissions and approval still follow the connected user, not the credited member', function () {
    // The member needs approval, the connected admin does not: the post goes straight to the queue.
    TryPostServer::actingAs($this->agent)->tool(CreatePostTool::class, [
        'social_account_id' => $this->linkedin->id,
        'content' => 'Planlı gönderi',
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'on_behalf_of_user_id' => $this->member->id,
    ])->assertOk();

    $post = Post::query()->sole();

    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->user_id)->toBe($this->member->id)
        ->and($post->agent_user_id)->toBe($this->agent->id);
});

test('create-posts-tool credits every post of the batch to the member', function () {
    TryPostServer::actingAs($this->agent)->tool(CreatePostsTool::class, [
        'status' => 'draft',
        'content' => 'İki kanala',
        'destinations' => [
            ['social_account_id' => $this->linkedin->id],
            ['social_account_id' => $this->x->id],
        ],
        'on_behalf_of_user_id' => $this->member->id,
    ])->assertOk()->assertStructuredContent(function (AssertableJson $json) {
        $json->has('posts', 2)
            ->where('posts.0.author.id', $this->member->id)
            ->where('posts.1.author.id', $this->member->id);
    });

    expect(Post::query()->pluck('user_id')->unique()->all())->toBe([$this->member->id])
        ->and(Post::query()->pluck('agent_user_id')->unique()->all())->toBe([$this->agent->id]);
});

test('an on_behalf_of_user_id that is not a member of the workspace is refused and nothing is created', function () {
    $outsider = workspaceOutsider($this->workspace);
    $stranger = User::factory()->create();

    foreach ([$outsider->id, $stranger->id, '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'] as $userId) {
        TryPostServer::actingAs($this->agent)->tool(CreatePostTool::class, [
            'social_account_id' => $this->linkedin->id,
            'content' => 'x',
            'on_behalf_of_user_id' => $userId,
        ])->assertHasErrors(['not a member of this workspace']);

        TryPostServer::actingAs($this->agent)->tool(CreatePostsTool::class, [
            'status' => 'draft',
            'content' => 'x',
            'destinations' => [['social_account_id' => $this->linkedin->id]],
            'on_behalf_of_user_id' => $userId,
        ])->assertHasErrors(['not a member of this workspace']);
    }

    TryPostServer::actingAs($this->agent)->tool(CreatePostTool::class, [
        'social_account_id' => $this->linkedin->id,
        'content' => 'x',
        'on_behalf_of_user_id' => 'behice',
    ])->assertHasErrors();

    expect(Post::query()->count())->toBe(0);
});

test('without on_behalf_of_user_id the post stays with the connected user', function () {
    TryPostServer::actingAs($this->agent)->tool(CreatePostTool::class, [
        'social_account_id' => $this->linkedin->id,
        'content' => 'Kendi gönderim',
    ])->assertOk();

    TryPostServer::actingAs($this->agent)->tool(CreatePostsTool::class, [
        'status' => 'draft',
        'content' => 'Kendi gönderim',
        'destinations' => [['social_account_id' => $this->x->id]],
        'on_behalf_of_user_id' => null,
    ])->assertOk();

    expect(Post::query()->pluck('user_id')->unique()->all())->toBe([$this->agent->id])
        ->and(Post::query()->whereNotNull('agent_user_id')->count())->toBe(0);
});

test('the post creating tools tell the agent about on_behalf_of_user_id', function (string $tool) {
    $definition = json_encode(app($tool)->toArray(), JSON_UNESCAPED_UNICODE);

    expect($definition)->toContain('on_behalf_of_user_id')
        ->toContain('Never guess it.')
        ->toContain('[TryPost kullanıcı: <uuid>]');
})->with([CreatePostTool::class, CreatePostsTool::class]);
