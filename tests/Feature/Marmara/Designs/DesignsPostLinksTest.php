<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use App\Actions\Media\SyncOwnedMedia;
use App\Actions\Post\CreatePosts;
use App\Actions\Post\HostInlineMedia;
use App\Enums\Media\Type as MediaType;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\DispatchWebhook;
use App\Jobs\PublishPost;
use App\Marmara\Designs\Actions\DesignActions;
use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Jobs\AddPublishedMediaToDesigns;
use App\Marmara\Designs\Jobs\SyncDesignRevisions;
use App\Marmara\Designs\Mcp\AddDesignTool;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignActivity;
use App\Marmara\Designs\Models\DesignFolder;
use App\Marmara\Designs\Models\DesignMedia;
use App\Marmara\Designs\Models\DesignPost;
use App\Marmara\Designs\Models\DesignSyncState;
use App\Marmara\Designs\Posts\DesignPostLinks;
use App\Marmara\Designs\Posts\RecordDesignPublication;
use App\Marmara\Designs\Support\DesignSource;
use App\Marmara\Designs\Support\DesignStatus;
use App\Marmara\Designs\Sync\DesignSync;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\MediaCopyBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Storage::fake();
    Queue::fake([SyncDesignRevisions::class, DispatchWebhook::class, PublishPost::class, AddPublishedMediaToDesigns::class]);
    ['workspace' => $this->workspace, 'user' => $this->owner] = createApiTestToken();
    designsConfigure($this->workspace);

    $this->linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $this->facebook = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    $this->member = workspaceMember($this->workspace, 'member', ['name' => 'Behice Kaya']);
});

/**
 * Posts made from temporary uploads by $user on the channels, like the
 * composer or the Echo bridge do.
 *
 * @param  list<Media>  $uploads
 * @param  list<SocialAccount>  $channels
 * @return Collection<int, Post>
 */
function designsPostsWithUploads(Workspace $workspace, User $user, array $uploads, array $channels, string $content = ''): Collection
{
    $hosted = HostInlineMedia::forPost($workspace, MediaType::cases(), [
        'media' => array_map(fn (Media $upload): array => ['upload_token' => $upload->upload_token], $uploads),
    ]);

    return CreatePosts::execute($workspace, $user, [
        'status' => PostStatus::Draft->value,
        'content' => $content,
        'media' => $hosted['media'],
        'label_ids' => [],
        'destinations' => array_map(fn (SocialAccount $channel): array => ['social_account_id' => $channel->id], $channels),
    ]);
}

/**
 * A media row of the post that holds the design's file (made from
 * `$version` of it when given), linked like the media's saved event does.
 */
function designsPutOnPost(Design $design, Post $post, int $position = 0, ?string $version = null): Media
{
    $media = Media::factory()->ownedByPost($post)->create(['order' => $position]);
    DesignMedia::remember($design, $media->id, $version);
    app(DesignPostLinks::class)->link($design, $post, $position);

    return $media;
}

/**
 * The draft's media without the given rows, saved like the composer does.
 *
 * @param  list<string>  $removedIds
 */
function designsRemoveFromPost(Post $post, array $removedIds): void
{
    $post->refresh()->load('workspace');
    $kept = collect($post->media)->reject(fn (array $item): bool => in_array($item['id'], $removedIds, true))->values()->all();

    MediaCopyBatch::run(fn (MediaCopyBatch $batch) => SyncOwnedMedia::execute($post, $kept, $batch));
}

function designsRunAutoAdd(Post $post): void
{
    app()->call([new AddPublishedMediaToDesigns($post->id), 'handle']);
}

test('"gönderi oluştur" links the design to the draft of every channel, and their media rows are known as the design', function () {
    $design = designsMake($this->workspace, ['drive_file_id' => 'filePoster', 'mime_type' => 'image/jpeg', 'name' => 'Afiş.jpg']);
    designsFakeDrive(content: ['filePoster' => designsJpeg()]);

    $this->actingAs($this->owner)->post("/designs/{$design->id}/posts", [
        'social_account_ids' => [$this->linkedin->id, $this->facebook->id],
    ])->assertRedirect();

    $posts = Post::query()->where('workspace_id', $this->workspace->id)->get();
    $links = DesignPost::query()->where('design_id', $design->id)->get();

    expect($posts)->toHaveCount(2)
        ->and($links->pluck('post_id')->sort()->values()->all())->toBe($posts->pluck('id')->sort()->values()->all())
        ->and($links->pluck('position')->unique()->all())->toBe([0])
        ->and($links->pluck('linked_by_user_id')->unique()->all())->toBe([$this->owner->id])
        ->and($links->whereNotNull('published_at'))->toBeEmpty();

    // The first channel took the upload, the second got a copy: both rows lead to the design.
    $rows = Media::query()->whereIn('post_id', $posts->pluck('id'))->pluck('id');
    expect(DesignMedia::query()->whereIn('media_id', $rows)->pluck('design_id')->unique()->all())->toBe([$design->id])
        ->and($design->fresh()->design_set_id)->toBeNull();
});

test('several picked designs become one carousel draft in the order they were picked, as one series', function () {
    $a = designsMake($this->workspace, ['drive_file_id' => 'fileA', 'mime_type' => 'image/jpeg', 'title' => 'Kapak']);
    $b = designsMake($this->workspace, ['drive_file_id' => 'fileB', 'mime_type' => 'image/jpeg', 'title' => 'Program']);
    $c = designsMake($this->workspace, ['drive_file_id' => 'fileC', 'mime_type' => 'image/jpeg', 'title' => 'Konuşmacılar']);
    designsFakeDrive(content: ['fileA' => designsJpeg(1080, 1350), 'fileB' => designsJpeg(1080, 1351), 'fileC' => designsJpeg(1080, 1352)]);

    // The three are visible in the designs workspace before the request.
    expect(app(DesignsConfig::class)->workspace()?->id)->toBe($this->workspace->id)
        ->and(Design::query()->where('workspace_id', $this->workspace->id)->whereNull('removed_at')->whereIn('id', [$a->id, $b->id, $c->id])->count())->toBe(3);

    $response = $this->actingAs($this->owner)->post('/designs/posts', [
        'design_ids' => [$c->id, $a->id, $b->id],
        'social_account_ids' => [$this->linkedin->id],
        'content' => 'Program açıklandı',
    ]);

    $response->assertSessionHasNoErrors();
    $post = Post::query()->where('workspace_id', $this->workspace->id)->sole();
    $response->assertRedirect(route('app.posts.edit', $post));

    // The post's media, in order, are the three designs in the picked order.
    $mediaIds = collect($post->media)->pluck('id')->all();
    $byMedia = DesignMedia::query()->whereIn('media_id', $mediaIds)->pluck('design_id', 'media_id');
    expect($mediaIds)->toHaveCount(3)
        ->and(array_map(fn (string $id): ?string => $byMedia[$id] ?? null, $mediaIds))->toBe([$c->id, $a->id, $b->id])
        ->and($post->content)->toBe('Program açıklandı');

    $links = DesignPost::query()->where('post_id', $post->id)->orderBy('position')->get();
    expect($links->pluck('design_id')->all())->toBe([$c->id, $a->id, $b->id])
        ->and($links->pluck('position')->all())->toBe([0, 1, 2]);

    $set = Design::query()->whereIn('id', [$a->id, $b->id, $c->id])->get()->keyBy('id');
    expect($set->pluck('design_set_id')->unique())->toHaveCount(1)
        ->and($set[$a->id]->design_set_id)->not->toBeNull()
        ->and([$set[$c->id]->set_position, $set[$a->id]->set_position, $set[$b->id]->set_position])->toBe([0, 1, 2])
        ->and(DesignActivity::query()->where('type', 'post_created')->count())->toBe(3);

    // The single design path still answers, and a design of another workspace is not found.
    ['workspace' => $other] = createApiTestToken();
    $stranger = designsMake($other);
    $this->actingAs($this->owner)->post('/designs/posts', [
        'design_ids' => [$a->id, $stranger->id],
        'social_account_ids' => [$this->linkedin->id],
    ])->assertNotFound();
});

test('a post an agent makes with the media_id add-design-tool answered is linked to that design on every channel', function () {
    designsConnectDrive($this->workspace);
    designsFakeDriveUploads();
    $upload = Media::factory()->stored()->temporaryUpload($this->workspace)->create(['original_filename' => 'whatsapp.jpg', 'size' => 200_000]);

    TryPostServer::actingAs($this->owner)->tool(AddDesignTool::class, [
        'media' => ['upload_token' => $upload->upload_token],
        'title' => 'Tanışma afişi',
        'on_behalf_of_user_id' => $this->member->id,
    ])->assertOk();

    $design = Design::query()->sole();
    expect(DesignMedia::query()->where('media_id', $upload->id)->value('design_id'))->toBe($design->id);

    TryPostServer::actingAs($this->owner)->tool(CreatePostsTool::class, [
        'status' => 'draft',
        'content' => 'Tanışma toplantısı',
        'media' => [['id' => $upload->id]],
        'destinations' => [
            ['social_account_id' => $this->linkedin->id],
            ['social_account_id' => $this->facebook->id],
        ],
        'on_behalf_of_user_id' => $this->member->id,
    ])->assertOk();

    $posts = Post::query()->where('workspace_id', $this->workspace->id)->pluck('id');
    expect($posts)->toHaveCount(2)
        ->and(DesignPost::query()->where('design_id', $design->id)->pluck('post_id')->sort()->values()->all())->toBe($posts->sort()->values()->all());
});

test('a linked post that goes out makes the design Kullanıldı with one activity line for the member, once', function () {
    $design = designsMake($this->workspace, ['status' => DesignStatus::Approved]);
    $archived = designsMake($this->workspace, ['status' => DesignStatus::Archived]);
    $post = Post::factory()->forAccount($this->linkedin)->create([
        'user_id' => $this->member->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PublishStatus::Publishing,
        'platform_name' => 'Marmara AI',
    ]);
    designsPutOnPost($design, $post, 0, $design->approvalVersion());
    designsPutOnPost($archived, $post, 1);

    $post->markPublicationPublished('urn:li:share:1', 'https://www.linkedin.com/feed/update/urn:li:share:1');

    $design->refresh();
    $activity = DesignActivity::query()->where('design_id', $design->id)->where('type', 'post_published')->sole();

    expect($design->status)->toBe(DesignStatus::Used)
        ->and($design->approved_version)->toBe($design->approvalVersion())
        ->and($archived->fresh()->status)->toBe(DesignStatus::Archived)
        ->and($activity->user_id)->toBe($this->member->id)
        ->and($activity->data['post_id'])->toBe($post->id)
        ->and($activity->data['platform'])->toBe('linkedin')
        ->and($activity->data['platform_label'])->toBe('LinkedIn')
        ->and($activity->data['account'])->toBe('Marmara AI')
        ->and($activity->data['by'])->toBe('Behice Kaya')
        ->and($activity->data['published_at'])->not->toBeNull()
        ->and(DesignActivity::query()->where('design_id', $archived->id)->where('type', 'post_published')->count())->toBe(1)
        ->and(DesignPost::query()->where('post_id', $post->id)->whereNull('published_at')->count())->toBe(0);
    Queue::assertPushed(AddPublishedMediaToDesigns::class, fn (AddPublishedMediaToDesigns $job): bool => $job->postId === $post->id);

    // Deleted on the network and back, a failed retry published again, a second run: nothing new.
    $post->refresh()->markPublicationDeleted();
    $post->refresh()->markPublicationRestored();
    $post->refresh()->writePublication(['publish_status' => PublishStatus::Failed]);
    $post->refresh()->markPublicationPublished('urn:li:share:1');
    app(RecordDesignPublication::class)->record($post->refresh());

    expect(DesignActivity::query()->where('type', 'post_published')->count())->toBe(2)
        ->and(DesignActivity::query()->where('design_id', $design->id)->where('type', 'status_changed')->count())->toBe(1);
});

test('a post that does not go out records nothing', function () {
    $design = designsMake($this->workspace, ['status' => DesignStatus::Approved]);
    $post = Post::factory()->forAccount($this->linkedin)->publishing()->create(['user_id' => $this->member->id]);
    app(DesignPostLinks::class)->link($design, $post, 0);

    $post->markPublicationFailed('Token expired');

    expect($design->fresh()->status)->toBe(DesignStatus::Approved)
        ->and(DesignActivity::query()->where('type', 'post_published')->exists())->toBeFalse();
    Queue::assertNotPushed(AddPublishedMediaToDesigns::class);
});

test('published media not in the gallery is added once, as a series credited to the member, and linked to every channel\'s post', function () {
    designsConnectDrive($this->workspace);
    designsFakeDriveUploads();
    $photos = [
        Media::factory()->stored()->temporaryUpload($this->workspace)->create(['original_filename' => 'a.jpg', 'size' => 200_000]),
        Media::factory()->stored()->temporaryUpload($this->workspace)->create(['original_filename' => 'b.jpg', 'size' => 200_000]),
    ];
    $posts = designsPostsWithUploads($this->workspace, $this->owner, $photos, [$this->linkedin, $this->facebook], '<p>Bahar şenliğinden kareler</p>');

    // Hermes made them for Behice over WhatsApp.
    Post::query()->whereIn('id', $posts->pluck('id'))->update(['user_id' => $this->member->id, 'agent_user_id' => $this->owner->id]);
    [$linkedinPost, $facebookPost] = [$posts[0]->refresh(), $posts[1]->refresh()];

    $linkedinPost->markPublicationPublished('urn:li:share:2');
    designsRunAutoAdd($linkedinPost->refresh());

    $designs = Design::query()->orderBy('set_position')->get();
    expect($designs)->toHaveCount(2)
        ->and($GLOBALS['designsUploads'])->toHaveCount(2)
        ->and($designs->pluck('source')->unique()->all())->toBe([DesignSource::WhatsApp])
        ->and($designs->pluck('uploader_user_id')->unique()->all())->toBe([$this->member->id])
        ->and($designs->pluck('status')->unique()->all())->toBe([DesignStatus::Used])
        ->and($designs->pluck('folder_path')->unique()->all())->toBe(['Paylaşılanlar/'.now()->format('Y-m')])
        ->and($designs->pluck('title')->all())->toBe(['Bahar şenliğinden kareler (1/2)', 'Bahar şenliğinden kareler (2/2)'])
        ->and($designs->pluck('design_set_id')->unique())->toHaveCount(1)
        ->and($designs[0]->design_set_id)->not->toBeNull()
        ->and($designs->pluck('set_position')->all())->toBe([0, 1])
        ->and(DesignPost::query()->where('post_id', $linkedinPost->id)->whereNotNull('published_at')->count())->toBe(2)
        ->and(DesignActivity::query()->where('type', 'post_published')->where('user_id', $this->member->id)->count())->toBe(2)
        ->and(DesignActivity::query()->where('type', 'status_changed')->count())->toBe(0);

    // The other channel published the same files: no new design, both linked to it too.
    $facebookPost->markPublicationPublished('fb_1');
    designsRunAutoAdd($facebookPost->refresh());
    designsRunAutoAdd($facebookPost->refresh());

    expect(Design::query()->count())->toBe(2)
        ->and($GLOBALS['designsUploads'])->toHaveCount(2)
        ->and(DesignPost::query()->where('post_id', $facebookPost->id)->orderBy('position')->pluck('design_id')->all())->toBe($designs->pluck('id')->all())
        ->and(DesignActivity::query()->where('type', 'post_published')->count())->toBe(4);
});

test('a published post\'s media that came from a design is only linked, and the rest is added with source site', function () {
    designsConnectDrive($this->workspace);
    designsFakeDriveUploads();
    $design = designsMake($this->workspace, ['status' => DesignStatus::Approved]);
    $fromGallery = Media::factory()->stored()->temporaryUpload($this->workspace)->create(['size' => 200_000]);
    DesignMedia::remember($design, $fromGallery->id);
    $fresh = Media::factory()->stored()->temporaryUpload($this->workspace)->create(['size' => 200_000]);

    $post = designsPostsWithUploads($this->workspace, $this->member, [$fromGallery, $fresh], [$this->linkedin])->sole();
    expect(DesignPost::query()->where('post_id', $post->id)->pluck('design_id')->all())->toBe([$design->id]);

    $post->refresh()->markPublicationPublished('urn:li:share:3');
    designsRunAutoAdd($post->refresh());

    $added = Design::query()->whereKeyNot($design->id)->sole();
    expect($GLOBALS['designsUploads'])->toHaveCount(1)
        ->and($added->source)->toBe(DesignSource::Site)
        ->and($added->uploader_user_id)->toBe($this->member->id)
        ->and($added->title)->toBe('LinkedIn paylaşımı '.now()->format('d.m.Y').' (2/2)')
        ->and($design->fresh()->status)->toBe(DesignStatus::Used)
        ->and($design->fresh()->design_set_id)->not->toBeNull()
        ->and($added->design_set_id)->toBe($design->fresh()->design_set_id)
        ->and(DesignPost::query()->where('post_id', $post->id)->orderBy('position')->pluck('design_id')->all())->toBe([$design->id, $added->id]);
});

test('without the drive upload permission, or with auto add off, published media is left alone', function () {
    designsFakeDriveUploads();
    $photo = Media::factory()->stored()->temporaryUpload($this->workspace)->create(['size' => 200_000]);
    $post = designsPostsWithUploads($this->workspace, $this->member, [$photo], [$this->linkedin])->sole();
    $post->refresh()->markPublicationPublished('urn:li:share:4');

    designsRunAutoAdd($post->refresh());
    expect(Design::query()->count())->toBe(0)
        ->and($GLOBALS['designsUploads'])->toBeEmpty();

    designsConnectDrive($this->workspace);
    config()->set('marmara-designs.auto_add.enabled', false);
    designsRunAutoAdd($post->refresh());
    expect(Design::query()->count())->toBe(0);

    config()->set('marmara-designs.auto_add.enabled', true);
    designsRunAutoAdd($post->refresh());
    expect(Design::query()->count())->toBe(1);
});

test('the gallery shows a series once, as its first design with the count, and the drawer lists it in order', function () {
    $setId = (string) Str::uuid();
    $first = designsMake($this->workspace, ['title' => 'Seri kapak', 'design_set_id' => $setId, 'set_position' => 0]);
    $second = designsMake($this->workspace, ['title' => 'Seri program', 'design_set_id' => $setId, 'set_position' => 1]);
    $third = designsMake($this->workspace, ['title' => 'Seri konuşmacılar', 'design_set_id' => $setId, 'set_position' => 2]);
    $alone = designsMake($this->workspace, ['title' => 'Tek afiş']);

    $post = Post::factory()->forAccount($this->linkedin)->publishing()->create(['user_id' => $this->member->id]);
    designsPutOnPost($alone, $post);
    $post->markPublicationPublished('urn:li:share:5');

    $this->actingAs($this->owner)->get('/designs')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('designs', 2)
            ->where('designs', fn (Collection $designs): bool => $designs->pluck('id')->sort()->values()->all() === collect([$first->id, $alone->id])->sort()->values()->all())
            ->where('designs', fn (Collection $designs): bool => $designs->firstWhere('id', $first->id)['set'] === ['id' => $setId, 'count' => 3, 'item_ids' => [$first->id, $second->id, $third->id]])
            ->where('designs', fn (Collection $designs): bool => $designs->firstWhere('id', $alone->id)['set'] === null
                && $designs->firstWhere('id', $alone->id)['last_publication']['platform_label'] === 'LinkedIn'
                && $designs->firstWhere('id', $alone->id)['last_publication']['by']['name'] === 'Behice Kaya')
            ->where('sharedBy', [['user_id' => $this->member->id, 'name' => 'Behice Kaya', 'count' => 1]]));

    // A search that matches only one member still shows the series once.
    $this->actingAs($this->owner)->get('/designs?'.http_build_query(['q' => 'konuşmacılar']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('designs', 1)
            ->where('designs.0.id', $first->id)
            ->where('designs.0.set.count', 3));

    $this->actingAs($this->owner)->get("/designs?design={$second->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selected.id', $second->id)
            ->where('selected.set_id', $setId)
            ->where('selected.set.count', 3)
            ->where('selected.set.items.0.id', $first->id)
            ->where('selected.set.items.1.id', $second->id)
            ->where('selected.set.items.2.id', $third->id)
            ->has('selected.publications', 0));

    $this->actingAs($this->owner)->get("/designs?design={$alone->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('selected.publications', 1)
            ->where('selected.publications.0.post_id', $post->id)
            ->where('selected.publications.0.platform', 'linkedin'));

    // The per-person count follows the date range.
    $this->actingAs($this->owner)->get('/designs?from='.now()->subYear()->format('Y-m-d').'&to='.now()->subMonths(6)->format('Y-m-d'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sharedBy', []));
});

test('a design whose file was taken off the draft before it went out is not recorded, and its link is dropped', function () {
    $design = designsMake($this->workspace, ['drive_file_id' => 'filePoster', 'mime_type' => 'image/jpeg', 'status' => DesignStatus::Approved]);
    designsFakeDrive(content: ['filePoster' => designsJpeg()]);

    $this->actingAs($this->owner)->post("/designs/{$design->id}/posts", ['social_account_ids' => [$this->linkedin->id]])->assertRedirect();
    $post = Post::query()->where('workspace_id', $this->workspace->id)->sole();
    expect(DesignPost::query()->where('post_id', $post->id)->pluck('design_id')->all())->toBe([$design->id]);

    designsRemoveFromPost($post, collect($post->refresh()->media)->pluck('id')->all());
    $post->refresh()->markPublicationPublished('urn:li:share:20');

    expect($design->fresh()->status)->toBe(DesignStatus::Approved)
        ->and(DesignActivity::query()->where('design_id', $design->id)->where('type', 'post_published')->exists())->toBeFalse()
        ->and(DesignActivity::query()->where('design_id', $design->id)->where('type', 'status_changed')->exists())->toBeFalse()
        ->and(DesignPost::query()->where('post_id', $post->id)->exists())->toBeFalse();

    $this->actingAs($this->owner)->get("/designs?design={$design->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('selected.publications', 0)
            ->where('designs.0.last_publication', null)
            ->where('sharedBy', []));
});

test('with two designs on a post and one taken off, only the one still on it is recorded', function () {
    $kept = designsMake($this->workspace, ['drive_file_id' => 'fileKept', 'mime_type' => 'image/jpeg', 'title' => 'Kapak', 'status' => DesignStatus::Approved]);
    $removed = designsMake($this->workspace, ['drive_file_id' => 'fileRemoved', 'mime_type' => 'image/jpeg', 'title' => 'Program', 'status' => DesignStatus::Approved]);
    designsFakeDrive(content: ['fileKept' => designsJpeg(1080, 1350), 'fileRemoved' => designsJpeg(1080, 1351)]);

    $this->actingAs($this->owner)->post('/designs/posts', [
        'design_ids' => [$removed->id, $kept->id],
        'social_account_ids' => [$this->linkedin->id],
    ])->assertSessionHasNoErrors();
    $post = Post::query()->where('workspace_id', $this->workspace->id)->sole();
    $removedMedia = DesignMedia::query()->where('design_id', $removed->id)->whereIn('media_id', collect($post->media)->pluck('id'))->pluck('media_id')->all();
    expect($removedMedia)->toHaveCount(1);

    designsRemoveFromPost($post, $removedMedia);
    $post->refresh()->markPublicationPublished('urn:li:share:21');

    expect($kept->fresh()->status)->toBe(DesignStatus::Used)
        ->and($removed->fresh()->status)->toBe(DesignStatus::Approved)
        ->and(DesignActivity::query()->where('type', 'post_published')->pluck('design_id')->all())->toBe([$kept->id])
        ->and(DesignPost::query()->where('post_id', $post->id)->pluck('design_id')->all())->toBe([$kept->id])
        ->and(DesignPost::query()->where('post_id', $post->id)->whereNotNull('published_at')->count())->toBe(1);

    $this->actingAs($this->owner)->get("/designs?design={$removed->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('selected.publications', 0)
            ->where('sharedBy', [['user_id' => $this->owner->id, 'name' => $this->owner->name, 'count' => 1]]));
});

test('the version the post carried is recorded when Drive moved on before it went out, flagged as changed after use', function () {
    DesignFolder::query()->create(['workspace_id' => $this->workspace->id, 'drive_id' => DESIGNS_ROOT, 'name' => 'Tasarımlar', 'path' => '', 'is_root' => true]);
    DesignSyncState::query()->create(['workspace_id' => $this->workspace->id, 'folder_id' => DESIGNS_ROOT, 'start_page_token' => 'token-100', 'status' => 'ok', 'last_full_sync_at' => now()]);
    $v1 = designsJpeg();
    $design = designsMake($this->workspace, ['drive_file_id' => 'filePoster', 'name' => 'Afiş.jpg', 'mime_type' => 'image/jpeg', 'md5' => md5($v1)]);
    app(DesignActions::class)->setStatus($design, $this->owner, DesignStatus::Approved);
    designsFakeDrive(content: ['filePoster' => $v1]);

    $this->actingAs($this->owner)->post("/designs/{$design->id}/posts", ['social_account_ids' => [$this->linkedin->id]])->assertRedirect();
    $post = Post::query()->where('workspace_id', $this->workspace->id)->sole();
    expect(DesignMedia::query()->whereIn('media_id', collect($post->media)->pluck('id'))->pluck('version')->all())->toBe([md5($v1)]);

    // V2 lands in Drive and the sync sees it: the approval goes back to review.
    $v2 = designsDriveFile('filePoster', 'Afiş.jpg', DESIGNS_ROOT, ['mimeType' => 'image/jpeg', 'md5Checksum' => 'md5-v2', 'headRevisionId' => 'rev-2']);
    designsFakeDrive(changes: ['token-100' => ['newStartPageToken' => 'token-101', 'changes' => [
        ['kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'filePoster', 'removed' => false, 'file' => $v2],
    ]]]);
    app(DesignSync::class)->run();
    expect($design->fresh())->status->toBe(DesignStatus::InReview)->md5->toBe('md5-v2');

    $post->refresh()->markPublicationPublished('urn:li:share:22');

    $design->refresh();
    expect($design->status)->toBe(DesignStatus::Used)
        ->and($design->approved_version)->toBe(md5($v1))
        ->and($design->changed_after_use_at)->not->toBeNull()
        ->and(DesignActivity::query()->where('design_id', $design->id)->where('type', 'post_published')->sole()->data['version'])->toBe(md5($v1))
        ->and(DesignActivity::query()->where('design_id', $design->id)->where('type', 'changed_after_use')->count())->toBe(1);

    // A later sync without new content does not flag it again; new content does.
    $flaggedAt = $design->changed_after_use_at;
    designsFakeDrive(changes: ['token-101' => ['newStartPageToken' => 'token-102', 'changes' => [
        ['kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'filePoster', 'removed' => false, 'file' => [...$v2, 'name' => 'Afiş son.jpg']],
    ]]]);
    app(DesignSync::class)->run();
    expect($design->fresh())->approved_version->toBe(md5($v1))
        ->changed_after_use_at->toEqual($flaggedAt)
        ->and(DesignActivity::query()->where('design_id', $design->id)->where('type', 'changed_after_use')->count())->toBe(1);

    designsFakeDrive(changes: ['token-102' => ['newStartPageToken' => 'token-103', 'changes' => [
        ['kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'filePoster', 'removed' => false, 'file' => [...$v2, 'md5Checksum' => 'md5-v3', 'headRevisionId' => 'rev-3']],
    ]]]);
    app(DesignSync::class)->run();
    expect($design->fresh()->approved_version)->toBe('md5-v3')
        ->and(DesignActivity::query()->where('design_id', $design->id)->where('type', 'changed_after_use')->count())->toBe(2);
});

test('a design file without a known version makes the design Kullanıldı but keeps its approved version', function () {
    $design = designsMake($this->workspace, ['md5' => 'md5-now', 'status' => DesignStatus::Approved, 'approved_version' => 'md5-approved']);
    $post = Post::factory()->forAccount($this->linkedin)->publishing()->create(['user_id' => $this->member->id]);
    designsPutOnPost($design, $post);

    $post->markPublicationPublished('urn:li:share:23');

    $design->refresh();
    expect($design->status)->toBe(DesignStatus::Used)
        ->and($design->approved_version)->toBe('md5-approved')
        ->and($design->changed_after_use_at)->toBeNull()
        ->and(DesignActivity::query()->where('design_id', $design->id)->where('type', 'post_published')->sole()->data)->toHaveKey('version', null)
        ->and(DesignActivity::query()->where('design_id', $design->id)->where('type', 'status_changed')->count())->toBe(1)
        ->and(DesignActivity::query()->where('design_id', $design->id)->where('type', 'changed_after_use')->exists())->toBeFalse();
});

test('a legacy draft without a version, published after Drive moved to V2, does not record V2 as the version used', function () {
    DesignFolder::query()->create(['workspace_id' => $this->workspace->id, 'drive_id' => DESIGNS_ROOT, 'name' => 'Tasarımlar', 'path' => '', 'is_root' => true]);
    DesignSyncState::query()->create(['workspace_id' => $this->workspace->id, 'folder_id' => DESIGNS_ROOT, 'start_page_token' => 'token-100', 'status' => 'ok', 'last_full_sync_at' => now()]);
    $v1 = designsJpeg();
    $design = designsMake($this->workspace, ['drive_file_id' => 'filePoster', 'name' => 'Afiş.jpg', 'mime_type' => 'image/jpeg', 'md5' => md5($v1)]);
    app(DesignActions::class)->setStatus($design, $this->owner, DesignStatus::Approved);
    designsFakeDrive(content: ['filePoster' => $v1]);

    $this->actingAs($this->owner)->post("/designs/{$design->id}/posts", ['social_account_ids' => [$this->linkedin->id]])->assertRedirect();
    $post = Post::query()->where('workspace_id', $this->workspace->id)->sole();

    // Remembered before the version column existed.
    DesignMedia::query()->where('design_id', $design->id)->update(['version' => null]);

    $v2 = designsDriveFile('filePoster', 'Afiş.jpg', DESIGNS_ROOT, ['mimeType' => 'image/jpeg', 'md5Checksum' => 'md5-v2', 'headRevisionId' => 'rev-2']);
    designsFakeDrive(changes: ['token-100' => ['newStartPageToken' => 'token-101', 'changes' => [
        ['kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'filePoster', 'removed' => false, 'file' => $v2],
    ]]]);
    app(DesignSync::class)->run();
    $before = $design->fresh();
    expect($before)->md5->toBe('md5-v2');

    $post->refresh()->markPublicationPublished('urn:li:share:25');

    $design->refresh();
    expect($design->status)->toBe(DesignStatus::Used)
        ->and($design->approved_version)->toBe($before->approved_version)
        ->and($design->approved_version)->not->toBe('md5-v2')
        ->and($design->changed_after_use_at)->toEqual($before->changed_after_use_at)
        ->and(DesignActivity::query()->where('design_id', $design->id)->where('type', 'post_published')->sole()->data)->toHaveKey('version', null)
        ->and(DesignActivity::query()->where('design_id', $design->id)->where('type', 'changed_after_use')->exists())->toBeFalse();
});

test('a channel\'s copy of a design file carries the version of the file it was copied from', function () {
    $design = designsMake($this->workspace, ['md5' => 'md5-v2']);
    $post = Post::factory()->forAccount($this->linkedin)->publishing()->create(['user_id' => $this->member->id]);
    $root = Media::factory()->temporaryUpload($this->workspace)->create();
    DesignMedia::remember($design, $root->id, 'md5-v1');

    // A copy lands on the post (SyncOwnedMedia::copy() writes meta.copied_from): linked through its root.
    $copy = Media::factory()->ownedByPost($post)->create(['meta' => ['width' => 1080, 'height' => 1350, 'copied_from' => $root->id]]);

    expect(DesignMedia::query()->where('media_id', $copy->id)->value('version'))->toBe('md5-v1')
        ->and(DesignPost::query()->where('post_id', $post->id)->pluck('design_id')->all())->toBe([$design->id]);

    $post->markPublicationPublished('urn:li:share:24');

    expect($design->fresh())->status->toBe(DesignStatus::Used)->approved_version->toBe('md5-v1')->changed_after_use_at->not->toBeNull();
});
