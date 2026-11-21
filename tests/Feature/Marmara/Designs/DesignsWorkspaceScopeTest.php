<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use App\Marmara\Designs\Mcp\CommentOnDesignTool;
use App\Marmara\Designs\Mcp\GetDesignTool;
use App\Marmara\Designs\Mcp\ListDesignsTool;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignComment;
use App\Marmara\Designs\Models\DesignDriveConnection;
use App\Mcp\Servers\TryPostServer;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

beforeEach(function () {
    ['workspace' => $this->club, 'user' => $this->owner] = createApiTestToken();
    designsConfigure($this->club);
    config()->set('marmara-designs.oauth.client_id', 'client-123.apps.googleusercontent.com');
    config()->set('marmara-designs.oauth.client_secret', 'secret-xyz');
    $this->design = designsMake($this->club);

    // The club's upload permission is fully set up: only scoping can stop an upload.
    DesignDriveConnection::query()->create([
        'workspace_id' => $this->club->id,
        'access_token' => 'lead-access',
        'refresh_token' => 'lead-refresh',
        'expires_at' => now()->addHour(),
        'folder_granted_at' => now(),
    ]);

    // An admin of another workspace, not a member of the club's.
    ['workspace' => $this->otherWorkspace, 'user' => $this->stranger, 'plain_token' => $token] = createApiTestToken();
    $this->strangerHeaders = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    Http::fake();
});

test('an admin of another workspace cannot upload into the club drive', function () {
    $this->actingAs($this->stranger)
        ->post('/designs/upload', ['file' => UploadedFile::fake()->createWithContent('x.png', designsPng(8, 8)), 'title' => 'x'])
        ->assertNotFound();

    Http::assertNothingSent();
    expect(Design::query()->count())->toBe(1);
});

test('an admin of another workspace gets 404 on the page, the proxies, the actions and the drive connection', function () {
    $this->actingAs($this->stranger)->get('/designs')->assertNotFound();
    $this->actingAs($this->stranger)->get("/designs/{$this->design->id}/thumbnail/400")->assertNotFound();
    $this->actingAs($this->stranger)->get("/designs/{$this->design->id}/original")->assertNotFound();
    $this->actingAs($this->stranger)->patch("/designs/{$this->design->id}/status", ['status' => 'approved'])->assertNotFound();
    $this->actingAs($this->stranger)->post("/designs/{$this->design->id}/comments", ['body' => 'x'])->assertNotFound();
    $this->actingAs($this->stranger)->post("/designs/{$this->design->id}/posts", ['social_account_ids' => [(string) str()->uuid()]])->assertNotFound();
    $this->actingAs($this->stranger)->get('/designs/drive/connect')->assertNotFound();
    $this->actingAs($this->stranger)->get('/designs/drive/callback?state=x&code=y')->assertNotFound();
    $this->actingAs($this->stranger)->delete('/designs/drive')->assertNotFound();

    Http::assertNothingSent();
    expect(DesignComment::query()->count())->toBe(0)
        ->and(DesignDriveConnection::query()->count())->toBe(1)
        ->and($this->design->fresh()->status->value)->toBe('draft');
});

test('the api answers 404 to keys of another workspace, even for a member of both', function () {
    $this->getJson('/api/designs', $this->strangerHeaders)->assertNotFound();
    $this->getJson("/api/designs/{$this->design->id}", $this->strangerHeaders)->assertNotFound();
    $this->postJson("/api/designs/{$this->design->id}/comments", ['body' => 'x'], $this->strangerHeaders)->assertNotFound();

    // The club owner also runs a second workspace and uses that workspace's key.
    $second = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $second->members()->attach($this->owner->id, membershipPivot('admin'));
    $key = passportToken($this->owner, $second);
    $this->getJson('/api/designs', ['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'])->assertNotFound();

    expect(DesignComment::query()->count())->toBe(0);
});

test('mcp refuses users of another workspace', function () {
    TryPostServer::actingAs($this->stranger)->tool(ListDesignsTool::class)->assertHasErrors(['Designs are not available in this workspace.']);
    TryPostServer::actingAs($this->stranger)->tool(GetDesignTool::class, ['design_id' => $this->design->id])->assertHasErrors(['Designs are not available in this workspace.']);
    TryPostServer::actingAs($this->stranger)->tool(CommentOnDesignTool::class, ['design_id' => $this->design->id, 'body' => 'x'])->assertHasErrors(['Designs are not available in this workspace.']);

    expect(DesignComment::query()->count())->toBe(0);
});

test('the sidebar entry is only for members of the designs workspace', function () {
    $share = Inertia::getShared('marmaraDesigns');

    $this->actingAs($this->stranger);
    expect($share())->toBeNull();

    $this->actingAs($this->owner);
    expect($share())->not->toBeNull();
});

test('without a workspace id the module runs only while the install has exactly one workspace', function () {
    config()->set('marmara-designs.workspace_id', null);

    // Two workspaces exist (the club's and the stranger's): no guessing.
    $this->actingAs($this->owner)->get('/designs')->assertNotFound();
    $this->actingAs($this->owner);
    expect(Inertia::getShared('marmaraDesigns')())->toBeNull();
});
