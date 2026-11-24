<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use App\Jobs\DispatchWebhook;
use App\Marmara\Designs\Jobs\SyncDesignRevisions;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignActivity;
use App\Marmara\Designs\Models\DesignDriveConnection;
use App\Marmara\Designs\Support\DesignSource;
use App\Marmara\Designs\Sync\DesignSync;
use App\Marmara\Designs\Upload\DriveUploadAuth;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Queue::fake([SyncDesignRevisions::class, DispatchWebhook::class]);
    ['workspace' => $this->workspace, 'user' => $this->owner, 'plain_token' => $token] = createApiTestToken();
    $this->owner->forceFill(['name' => 'Yasir Arslan', 'email' => 'yasir@club.org'])->save();
    $this->headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    designsConfigure($this->workspace);
    $this->elif = workspaceMember($this->workspace, 'member', ['name' => 'Elif Tasarım', 'email' => 'elif@club.org']);
});

function designsSourceBackfill(): void
{
    $migration = require app_path('Marmara/Designs/database/migrations/2026_11_24_120000_add_marmara_design_uploader_source.php');
    $migration->backfill();
}

test('the migration backfills the person and the source of existing designs', function () {
    $site = designsMake($this->workspace, ['uploader_name' => 'Yasir Arslan']);
    DesignActivity::record($site, $this->owner, 'uploaded', ['name' => 'Yasir Arslan']);
    $whatsapp = designsMake($this->workspace, ['uploader_name' => 'Elif (WhatsApp)']);
    DesignActivity::record($whatsapp, $this->owner, 'uploaded', ['name' => 'Elif (WhatsApp)']);
    $unknownSender = designsMake($this->workspace, ['uploader_name' => 'Mehmet (WhatsApp)']);
    DesignActivity::record($unknownSender, $this->owner, 'uploaded', ['name' => 'Mehmet (WhatsApp)']);
    $drive = designsMake($this->workspace, ['owner_name' => 'Elif T', 'owner_email' => 'ELIF@Club.org']);
    $stranger = designsMake($this->workspace, ['owner_name' => 'Biri', 'owner_email' => 'someone@gmail.com']);
    User::factory()->create(['email' => 'outsider@club.org']);
    $outsider = designsMake($this->workspace, ['owner_name' => 'Dışarıdan', 'owner_email' => 'outsider@club.org']);

    DB::table('marmara_designs')->update(['source' => DesignSource::DEFAULT->value, 'uploader_user_id' => null]);
    designsSourceBackfill();

    $row = fn (Design $design): array => [$design->fresh()->source, $design->fresh()->uploader_user_id];

    expect($row($site))->toBe([DesignSource::Site, $this->owner->id])
        ->and($row($whatsapp))->toBe([DesignSource::WhatsApp, $this->elif->id])
        ->and($row($unknownSender))->toBe([DesignSource::WhatsApp, null])
        ->and($row($drive))->toBe([DesignSource::Drive, $this->elif->id])
        ->and($row($stranger))->toBe([DesignSource::Drive, null])
        ->and($row($outsider))->toBe([DesignSource::Drive, null])
        ->and($whatsapp->fresh()->uploader_name)->toBe('Elif (WhatsApp)');
});

test('the sync credits a dropped file to the member with the drive owner\'s email and keeps what tryPost recorded', function () {
    $existing = designsMake($this->workspace, ['drive_file_id' => 'fileOld', 'uploader_name' => 'Yasir Arslan', 'source' => DesignSource::Site, 'uploader_user_id' => $this->owner->id]);

    designsFakeDrive([DESIGNS_ROOT => [
        designsDriveFile('fileMember', 'Afiş.png', DESIGNS_ROOT, ['owners' => [['displayName' => 'Elif T', 'emailAddress' => 'Elif@CLUB.org']]]),
        designsDriveFile('fileStranger', 'Logo.png', DESIGNS_ROOT, ['owners' => [['displayName' => 'Biri', 'emailAddress' => 'someone@gmail.com']]]),
        designsDriveFile('fileWhatsApp', 'Klip.png', DESIGNS_ROOT, ['appProperties' => ['trypost_uploader' => 'Elif Tasarım', 'trypost_uploader_id' => $this->elif->id, 'trypost_source' => 'whatsapp']]),
        designsDriveFile('fileOld', 'Eski.png', DESIGNS_ROOT, ['appProperties' => ['trypost_uploader' => 'Yasir Arslan']]),
    ]]);

    app(DesignSync::class)->run();

    $credit = fn (string $id): array => [Design::query()->where('drive_file_id', $id)->value('source'), Design::query()->where('drive_file_id', $id)->value('uploader_user_id')];

    expect($credit('fileMember'))->toBe(['drive', $this->elif->id])
        ->and($credit('fileStranger'))->toBe(['drive', null])
        ->and($credit('fileWhatsApp'))->toBe(['whatsapp', $this->elif->id])
        ->and($credit('fileOld'))->toBe(['site', $this->owner->id])
        ->and($existing->fresh()->uploader_name)->toBe('Yasir Arslan');
});

test('the uploader filter goes per person, and an old name still filters', function () {
    $site = designsMake($this->workspace, ['uploader_name' => 'Yasir Arslan', 'source' => DesignSource::Site, 'uploader_user_id' => $this->owner->id]);
    $whatsapp = designsMake($this->workspace, ['uploader_name' => 'Yasir Arslan', 'source' => DesignSource::WhatsApp, 'uploader_user_id' => $this->owner->id]);
    $nameOnly = designsMake($this->workspace, ['uploader_name' => 'Mehmet (WhatsApp)', 'source' => DesignSource::WhatsApp]);
    $drive = designsMake($this->workspace, ['owner_name' => 'Yusuf Kulüp', 'source' => DesignSource::Drive]);

    $ids = fn (string $uploader): array => collect($this->getJson('/api/designs?'.http_build_query(['uploader' => $uploader]), $this->headers)->assertOk()->json('data'))->pluck('id')->all();

    expect($ids($this->owner->id))->toEqualCanonicalizing([$site->id, $whatsapp->id])
        ->and($ids('Mehmet (WhatsApp)'))->toBe([$nameOnly->id])
        ->and($ids('Yusuf Kulüp'))->toBe([$drive->id])
        ->and($ids('Yasir Arslan'))->toEqualCanonicalizing([$site->id, $whatsapp->id])
        ->and($ids($this->elif->id))->toBe([]);

    $item = collect($this->getJson('/api/designs', $this->headers)->json('data'))->firstWhere('id', $whatsapp->id);
    expect($item['uploader'])->toBe(['id' => $this->owner->id, 'name' => 'Yasir Arslan'])
        ->and($item['source'])->toBe('whatsapp');

    $this->actingAs($this->owner)->get('/designs')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filterOptions.uploaders', [
                ['value' => 'Mehmet (WhatsApp)', 'label' => 'Mehmet (WhatsApp)'],
                ['value' => $this->owner->id, 'label' => 'Yasir Arslan'],
                ['value' => 'Yusuf Kulüp', 'label' => 'Yusuf Kulüp'],
            ])
            ->etc());
});

test('the page upload credits the member who uploads with source site and records both in appProperties', function () {
    DesignDriveConnection::query()->create([
        'workspace_id' => $this->workspace->id,
        'google_email' => 'lead@example.com',
        'access_token' => 'lead-access',
        'refresh_token' => 'lead-refresh',
        'expires_at' => now()->addHour(),
        'scopes' => [DriveUploadAuth::SCOPE],
        'folder_granted_at' => now(),
    ]);
    config()->set('marmara-designs.oauth.client_id', 'client-123.apps.googleusercontent.com');
    config()->set('marmara-designs.oauth.client_secret', 'secret-xyz');

    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_starts_with($url, 'https://oauth2.googleapis.com/token') => Http::response(['access_token' => 'sa-access-token', 'expires_in' => 3599]),
            $request->method() === 'POST' && str_contains($url, 'uploadType=resumable') => Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=abc']),
            $request->method() === 'PUT' => Http::response(designsDriveFile('fileSite', 'Afiş.png', DESIGNS_ROOT)),
            default => Http::response(['error' => ['code' => 404, 'message' => "unexpected {$request->method()} {$url}"]], 404),
        };
    });

    $this->actingAs($this->owner)
        ->post('/designs/upload', ['file' => UploadedFile::fake()->createWithContent('afis.png', designsPng(20, 20)), 'title' => 'Afiş'])
        ->assertRedirect();

    $design = Design::query()->sole();
    expect($design->source)->toBe(DesignSource::Site)
        ->and($design->uploader_user_id)->toBe($this->owner->id)
        ->and($design->uploader_name)->toBe('Yasir Arslan');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), 'uploadType=resumable')
        && data_get($request->data(), 'appProperties.trypost_uploader_id') === $this->owner->id
        && data_get($request->data(), 'appProperties.trypost_source') === 'site');
});
