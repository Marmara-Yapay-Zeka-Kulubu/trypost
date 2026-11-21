<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use App\Enums\Post\Status as PostStatus;
use App\Enums\Webhook\EventType;
use App\Jobs\DispatchWebhook;
use App\Marmara\Designs\Actions\DesignActions;
use App\Marmara\Designs\Jobs\SyncDesignRevisions;
use App\Marmara\Designs\Mcp\CommentOnDesignTool;
use App\Marmara\Designs\Mcp\GetDesignTool;
use App\Marmara\Designs\Mcp\ListDesignsTool;
use App\Marmara\Designs\Models\DesignActivity;
use App\Marmara\Designs\Models\DesignComment;
use App\Marmara\Designs\Sync\DesignSync;
use App\Marmara\Inbox\Mcp\ListInboxItemsTool;
use App\Mcp\Servers\TryPostServer;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Webhook;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    Queue::fake([DispatchWebhook::class, SyncDesignRevisions::class]);
    ['workspace' => $this->workspace, 'user' => $this->owner] = createApiTestToken();
    designsConfigure($this->workspace);
    $this->channel = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    $this->design = designsMake($this->workspace, ['drive_file_id' => 'filePoster', 'title' => 'Bahar afişi', 'folder_path' => 'Bahar Şenliği', 'mime_type' => 'image/png']);

    Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [EventType::DesignAdded->value, EventType::DesignCommented->value, EventType::DesignStatusChanged->value],
    ]);
});

test('"gönderi oluştur" downloads the original from drive and attaches it byte for byte to a new draft', function () {
    $bytes = designsPng(1080, 1350);
    designsFakeDrive(content: ['filePoster' => $bytes]);

    $response = $this->actingAs($this->owner)->post("/designs/{$this->design->id}/posts", [
        'social_account_ids' => [$this->channel->id],
        'content' => 'Bahar şenliğine bekleriz!',
    ]);

    $post = Post::query()->where('workspace_id', $this->workspace->id)->sole();
    $response->assertRedirect(route('app.posts.edit', $post));

    $media = Media::query()->where('post_id', $post->id)->sole();
    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->social_account_id)->toBe($this->channel->id)
        ->and($post->content)->toBe('Bahar şenliğine bekleriz!')
        ->and($media->mime_type)->toBe('image/png')
        ->and($media->size)->toBe(strlen($bytes))
        ->and(Storage::get($media->path))->toBe($bytes)
        ->and(md5((string) Storage::get($media->path)))->toBe(md5($bytes))
        ->and(DesignActivity::query()->where('type', 'post_created')->sole()->data)->toBe(['post_ids' => [$post->id]]);
});

test('a google drawing becomes a png export, and a post needs at least one channel', function () {
    $drawing = designsMake($this->workspace, ['drive_file_id' => 'fileDrawing', 'mime_type' => 'application/vnd.google-apps.drawing', 'kind' => 'drawing', 'size' => null]);
    $png = designsPng(300, 200);
    designsFakeDrive(content: ['fileDrawing@export' => $png]);

    $this->actingAs($this->owner)->post("/designs/{$drawing->id}/posts", ['social_account_ids' => []])->assertSessionHasErrors('social_account_ids');

    $this->actingAs($this->owner)->post("/designs/{$drawing->id}/posts", ['social_account_ids' => [$this->channel->id]])->assertRedirect();
    expect(Storage::get(Media::query()->whereNotNull('post_id')->sole()->path))->toBe($png);
});

test('a design that is gone from drive cannot become a post', function () {
    $this->design->forceFill(['removed_at' => now()])->save();

    $this->actingAs($this->owner)->post("/designs/{$this->design->id}/posts", ['social_account_ids' => [$this->channel->id]])->assertNotFound();
    expect(Post::query()->count())->toBe(0);
});

test('a comment fires design.commented with the design, the comment and its author', function () {
    $this->actingAs($this->owner)->post("/designs/{$this->design->id}/comments", ['body' => 'Harika olmuş']);

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job): bool {
        return $job->eventType === 'design.commented'
            && $job->payload['design']['id'] === $this->design->id
            && $job->payload['design']['title'] === 'Bahar afişi'
            && $job->payload['design']['uploader'] === ['name' => 'Yusuf Kulüp']
            && $job->payload['design']['folder'] === 'Bahar Şenliği'
            && str_contains((string) $job->payload['design']['thumbnail_url'], 'signature=')
            && $job->payload['design']['url'] === route('marmara.designs.index', ['design' => $this->design->id])
            && $job->payload['comment']['body'] === 'Harika olmuş'
            && $job->payload['comment']['author'] === ['id' => $this->owner->id, 'name' => $this->owner->name]
            && ! array_key_exists('owner_email', $job->payload['design']);
    });
});

test('a status change fires design.status_changed with from, to and who', function () {
    $this->actingAs($this->owner)->patch("/designs/{$this->design->id}/status", ['status' => 'approved']);

    Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === 'design.status_changed'
        && $job->payload['status']['from'] === 'draft'
        && $job->payload['status']['to'] === 'approved'
        && $job->payload['status']['changed_by']['id'] === $this->owner->id
        && $job->payload['design']['status'] === 'approved');

    // Choosing the same status again changes nothing and sends nothing.
    $this->actingAs($this->owner)->patch("/designs/{$this->design->id}/status", ['status' => 'approved']);
    Queue::assertPushed(DispatchWebhook::class, 1);
});

test('design.added fires for new files after the first sync, not for the first sync\'s backlog nor for old files', function () {
    designsFakeDrive([DESIGNS_ROOT => [designsDriveFile('fileBacklog', 'Eski.png', DESIGNS_ROOT)]]);
    app(DesignSync::class)->run();
    Queue::assertNotPushed(DispatchWebhook::class);

    designsFakeDrive(changes: ['token-100' => [
        'newStartPageToken' => 'token-101',
        'changes' => [
            ['kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'fileFresh', 'removed' => false,
                'file' => designsDriveFile('fileFresh', 'Yeni story.png', DESIGNS_ROOT, ['createdTime' => now()->subMinutes(3)->toIso8601String()])],
            ['kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'fileOld', 'removed' => false,
                'file' => designsDriveFile('fileOld', 'Geçen yıl.png', DESIGNS_ROOT, ['createdTime' => now()->subDays(40)->toIso8601String()])],
        ],
    ]]);
    app(DesignSync::class)->run();

    Queue::assertPushed(DispatchWebhook::class, 1);
    Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === 'design.added' && $job->payload['design']['title'] === 'Yeni story');
    expect(DesignActivity::query()->where('type', 'added')->count())->toBe(3);
});

test('no design webhook fires while the module is off', function () {
    config()->set('marmara-designs.enabled', false);

    app(DesignActions::class)->comment($this->design, $this->owner, 'Sessiz');

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('the mcp tools list, read and comment on designs, and hide while the module is off', function () {
    TryPostServer::actingAs($this->owner)->tools()->assertRegistered([ListDesignsTool::class, GetDesignTool::class, CommentOnDesignTool::class]);

    TryPostServer::actingAs($this->owner)->tool(ListDesignsTool::class, ['folder' => 'Bahar Şenliği'])
        ->assertOk()
        ->assertSee($this->design->id);

    TryPostServer::actingAs($this->owner)->tool(GetDesignTool::class, ['design_id' => $this->design->id])
        ->assertOk()
        ->assertSee('signature=');

    TryPostServer::actingAs(workspaceMember($this->workspace, 'approval'))->tool(CommentOnDesignTool::class, ['design_id' => $this->design->id, 'body' => 'Hermes: logo biraz küçük'])
        ->assertOk();

    expect(DesignComment::query()->sole()->body)->toBe('Hermes: logo biraz küçük');
    Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === 'design.commented');

    ['user' => $stranger] = createApiTestToken();
    TryPostServer::actingAs($stranger)->tool(GetDesignTool::class, ['design_id' => $this->design->id])->assertHasErrors(['Design not found.']);

    config()->set('marmara-designs.enabled', false);
    TryPostServer::actingAs($this->owner)->tools()->assertNotRegistered([ListDesignsTool::class, GetDesignTool::class, CommentOnDesignTool::class]);
});

test('the mcp tools keep the inbox tools next to them', function () {
    config()->set('marmara-inbox.enabled', true);

    TryPostServer::actingAs($this->owner)->tools()->assertRegistered([
        ListDesignsTool::class,
        ListInboxItemsTool::class,
    ]);
});
