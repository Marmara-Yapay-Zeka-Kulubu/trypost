<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use App\Jobs\DispatchWebhook;
use App\Marmara\Designs\Jobs\SyncDesignRevisions;
use App\Marmara\Designs\Mcp\AddDesignTool;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignActivity;
use App\Marmara\Designs\Models\DesignDriveConnection;
use App\Marmara\Designs\Upload\DriveUploadAuth;
use App\Mcp\Servers\TryPostServer;
use App\Models\Media;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

beforeEach(function () {
    Storage::fake();
    Queue::fake([SyncDesignRevisions::class, DispatchWebhook::class]);
    ['workspace' => $this->workspace, 'user' => $this->owner] = createApiTestToken();
    designsConfigure($this->workspace);
    config()->set('marmara-designs.oauth.client_id', 'client-123.apps.googleusercontent.com');
    config()->set('marmara-designs.oauth.client_secret', 'secret-xyz');

    // What Echo leaves behind for a WhatsApp video: a temporary upload with a token.
    $this->upload = Media::factory()->stored()->video()->temporaryUpload($this->workspace)->create([
        'original_filename' => 'whatsapp-video.mp4',
        'size' => 12_163_482,
    ]);
    $this->token = (string) $this->upload->upload_token;
});

function designsMcpConnect($workspace, array $attributes = []): DesignDriveConnection
{
    return DesignDriveConnection::query()->create([
        'workspace_id' => $workspace->id,
        'google_email' => 'lead@example.com',
        'access_token' => 'lead-access',
        'refresh_token' => 'lead-refresh',
        'expires_at' => now()->addHour(),
        'scopes' => [DriveUploadAuth::SCOPE],
        'folder_granted_at' => now(),
        ...$attributes,
    ]);
}

/**
 * Fakes the Drive upload: folder lookups find nothing, new folders are
 * created, and the uploaded file comes back with the name and the uploader
 * the resumable request asked for.
 */
function designsMcpFakeUpload(): void
{
    $sent = ['name' => 'x.mp4', 'parent' => DESIGNS_ROOT, 'uploader' => null];

    Http::fake(function (Request $request) use (&$sent) {
        $url = $request->url();

        if (str_starts_with($url, 'https://oauth2.googleapis.com/token')) {
            return Http::response(['access_token' => 'sa-access-token', 'expires_in' => 3599]);
        }

        if ($request->method() === 'GET' && str_contains($url, '/drive/v3/files?')) {
            return Http::response(['files' => []]);
        }

        if ($request->method() === 'POST' && str_contains($url, 'uploadType=resumable')) {
            $sent = [
                'name' => (string) data_get($request->data(), 'name'),
                'parent' => (string) data_get($request->data(), 'parents.0'),
                'uploader' => data_get($request->data(), 'appProperties.trypost_uploader'),
            ];

            return Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=abc']);
        }

        if ($request->method() === 'POST' && str_contains($url, '/drive/v3/files?')) {
            return Http::response(designsDriveFolder('fld'.md5((string) data_get($request->data(), 'name')), (string) data_get($request->data(), 'name'), DESIGNS_ROOT));
        }

        if ($request->method() === 'PUT') {
            return Http::response(designsDriveFile('fileVideo', $sent['name'], $sent['parent'], [
                'mimeType' => 'video/mp4',
                'size' => '12163482',
                'imageMediaMetadata' => null,
                'videoMediaMetadata' => ['width' => 1080, 'height' => 1920, 'durationMillis' => '15000'],
                'appProperties' => ['trypost_uploader' => $sent['uploader']],
            ]));
        }

        return Http::response(['error' => ['code' => 404, 'message' => "unexpected {$request->method()} {$url}"]], 404);
    });
}

test('the tool is registered with the other designs tools and is a write tool', function () {
    TryPostServer::actingAs($this->owner)->tools()->assertRegistered([AddDesignTool::class]);

    $reflection = new ReflectionClass(AddDesignTool::class);
    $readOnly = $reflection->getAttributes(IsReadOnly::class)[0]->newInstance();

    expect($readOnly->value)->toBeFalse()
        ->and((new AddDesignTool)->name())->toBe('add-design-tool');

    config()->set('marmara-designs.enabled', false);
    TryPostServer::actingAs($this->owner)->tools()->assertNotRegistered([AddDesignTool::class]);
});

test('a whatsapp video goes up to drive with the lead\'s token, lands in the gallery and gets its tags', function () {
    designsMcpConnect($this->workspace);
    designsMcpFakeUpload();

    TryPostServer::actingAs($this->owner)->tool(AddDesignTool::class, [
        'media' => ['upload_token' => $this->token],
        'title' => 'Aranan Aslan',
        'tags' => ['Aranan Aslan', '#video'],
        'folder' => 'Etkinlikler',
        'on_behalf_of' => 'Behice',
    ])->assertOk()->assertStructuredContent(function (AssertableJson $json) {
        $design = Design::query()->sole();

        $json->where('id', $design->id)
            ->where('title', 'Aranan Aslan')
            ->where('kind', 'video')
            ->where('tags', ['aranan aslan', 'video'])
            ->where('folder', 'Etkinlikler')
            ->where('uploader.name', 'Behice (WhatsApp)')
            ->where('url', route('marmara.designs.index', ['design' => $design->id]))
            ->where('media_id', $this->upload->id)
            ->etc();
    });

    $design = Design::query()->sole();
    expect($design->workspace_id)->toBe($this->workspace->id)
        ->and($design->drive_file_id)->toBe('fileVideo')
        ->and($design->kind)->toBe('video')
        ->and($design->uploader_name)->toBe('Behice (WhatsApp)')
        ->and($design->folder_path)->toBe('Etkinlikler')
        ->and($design->tags()->pluck('tag')->sort()->values()->all())->toBe(['aranan aslan', 'video'])
        ->and(DesignActivity::query()->where('design_id', $design->id)->where('type', 'uploaded')->value('user_id'))->toBe($this->owner->id)
        ->and(DesignActivity::query()->where('design_id', $design->id)->where('type', 'tags_changed')->value('user_id'))->toBe($this->owner->id);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), 'uploadType=resumable')
        && $request->hasHeader('Authorization', 'Bearer lead-access')
        && data_get($request->data(), 'name') === 'Aranan Aslan.mp4'
        && data_get($request->data(), 'appProperties.trypost_uploader') === 'Behice (WhatsApp)');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->body() === 'media bytes');
    Queue::assertPushed(SyncDesignRevisions::class);
});

test('without a title the file name is used and the token user is the uploader', function () {
    designsMcpConnect($this->workspace);
    designsMcpFakeUpload();

    TryPostServer::actingAs($this->owner)->tool(AddDesignTool::class, ['media' => ['upload_token' => $this->token]])->assertOk();

    $design = Design::query()->sole();
    expect($design->title)->toBe('whatsapp-video')
        ->and($design->uploader_name)->toBe($this->owner->name)
        ->and($design->folder_path)->toBe('')
        ->and($design->tags()->count())->toBe(0);
});

test('an upload token is spent by the first call', function () {
    designsMcpConnect($this->workspace);
    designsMcpFakeUpload();

    TryPostServer::actingAs($this->owner)->tool(AddDesignTool::class, ['media' => ['upload_token' => $this->token]])->assertOk();

    expect($this->upload->fresh()->upload_token)->toBeNull();

    TryPostServer::actingAs($this->owner)->tool(AddDesignTool::class, ['media' => ['upload_token' => $this->token]])
        ->assertHasErrors(['This upload expired.']);

    expect(Design::query()->count())->toBe(1);
});

test('an existing media id of the workspace can be added and stays as it is', function () {
    designsMcpConnect($this->workspace);
    designsMcpFakeUpload();

    TryPostServer::actingAs($this->owner)->tool(AddDesignTool::class, ['media' => ['id' => $this->upload->id], 'title' => 'Klip'])->assertOk();

    expect(Design::query()->sole()->title)->toBe('Klip')
        ->and($this->upload->fresh()->upload_token)->toBe($this->token);
});

test('without the drive upload permission the tool says so and keeps the token', function () {
    TryPostServer::actingAs($this->owner)->tool(AddDesignTool::class, ['media' => ['upload_token' => $this->token]])
        ->assertHasErrors(['needs a Drive connection']);

    designsMcpConnect($this->workspace, ['folder_granted_at' => null]);
    TryPostServer::actingAs($this->owner)->tool(AddDesignTool::class, ['media' => ['upload_token' => $this->token]])
        ->assertHasErrors(['shared folder is not picked yet']);

    config()->set('marmara-designs.oauth.client_id', null);
    TryPostServer::actingAs($this->owner)->tool(AddDesignTool::class, ['media' => ['upload_token' => $this->token]])
        ->assertHasErrors(['needs a Drive connection']);

    expect(Design::query()->count())->toBe(0)
        ->and($this->upload->fresh()->upload_token)->toBe($this->token);
});

test('a file over the module limit or of another type is refused before the token is spent', function () {
    designsMcpConnect($this->workspace);
    config()->set('marmara-designs.upload_max_mb', 10);

    TryPostServer::actingAs($this->owner)->tool(AddDesignTool::class, ['media' => ['upload_token' => $this->token]])
        ->assertHasErrors(['at most 10 MB']);

    $gif = Media::factory()->stored()->temporaryUpload($this->workspace)->create(['mime_type' => 'image/gif', 'original_filename' => 'a.gif', 'size' => 1000]);
    TryPostServer::actingAs($this->owner)->tool(AddDesignTool::class, ['media' => ['upload_token' => (string) $gif->upload_token]])
        ->assertHasErrors(['Only images']);

    expect(Design::query()->count())->toBe(0)
        ->and($this->upload->fresh()->upload_token)->toBe($this->token);
});

test('a failed drive upload gives the token back', function () {
    designsMcpConnect($this->workspace);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa', 'expires_in' => 3599]),
        'www.googleapis.com/upload/drive/v3/files*' => Http::response(['error' => ['code' => 403, 'message' => 'The user\'s Drive storage quota has been exceeded.', 'errors' => [['reason' => 'storageQuotaExceeded']]]], 403),
    ]);

    TryPostServer::actingAs($this->owner)->tool(AddDesignTool::class, ['media' => ['upload_token' => $this->token]])
        ->assertHasErrors(['storage is full']);

    expect($this->upload->fresh()->upload_token)->toBe($this->token)
        ->and(Design::query()->count())->toBe(0);
});

test('another workspace cannot add designs nor use this workspace\'s files', function () {
    designsMcpConnect($this->workspace);
    designsMcpFakeUpload();
    ['user' => $stranger, 'workspace' => $other] = createApiTestToken();

    TryPostServer::actingAs($stranger)->tool(AddDesignTool::class, ['media' => ['upload_token' => $this->token]])
        ->assertHasErrors(['Designs are not available in this workspace.']);

    // A file of another workspace is not found from the designs workspace.
    $foreign = Media::factory()->stored()->video()->temporaryUpload($other)->create();
    TryPostServer::actingAs($this->owner)->tool(AddDesignTool::class, ['media' => ['upload_token' => (string) $foreign->upload_token]])
        ->assertHasErrors(['This upload expired.']);
    TryPostServer::actingAs($this->owner)->tool(AddDesignTool::class, ['media' => ['id' => $foreign->id]])
        ->assertHasErrors(['Media not found.']);

    expect(Design::query()->count())->toBe(0)
        ->and($this->upload->fresh()->upload_token)->toBe($this->token)
        ->and($foreign->fresh()->upload_token)->not->toBeNull();
});

test('media takes exactly one of upload_token or id', function () {
    designsMcpConnect($this->workspace);

    TryPostServer::actingAs($this->owner)->tool(AddDesignTool::class, ['media' => ['upload_token' => $this->token, 'id' => $this->upload->id]])
        ->assertHasErrors();
    TryPostServer::actingAs($this->owner)->tool(AddDesignTool::class, ['media' => []])
        ->assertHasErrors();

    expect($this->upload->fresh()->upload_token)->toBe($this->token);
});
