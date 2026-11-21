<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use App\Marmara\Designs\Jobs\SyncDesignRevisions;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignDriveConnection;
use App\Marmara\Designs\Upload\DesignUploader;
use App\Marmara\Designs\Upload\DesignUploadUnavailable;
use App\Marmara\Designs\Upload\DriveUploadAuth;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([SyncDesignRevisions::class]);
    ['workspace' => $this->workspace, 'user' => $this->owner] = createApiTestToken();
    designsConfigure($this->workspace);
    config()->set('marmara-designs.oauth.client_id', 'client-123.apps.googleusercontent.com');
    config()->set('marmara-designs.oauth.client_secret', 'secret-xyz');
});

function designsConnection($workspace, array $attributes = []): DesignDriveConnection
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

test('connecting asks google for drive.file only, offline, with pkce and a state', function () {
    $location = (string) $this->actingAs($this->owner)->get('/designs/drive/connect')->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($query['scope'])->toBe('https://www.googleapis.com/auth/drive.file')
        ->and($query['access_type'])->toBe('offline')
        ->and($query['prompt'])->toBe('consent')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['redirect_uri'])->toBe(route('marmara.designs.drive.callback'))
        ->and(strlen($query['state']))->toBe(40)
        ->and(session('marmara-designs.oauth.state'))->toBe($query['state'])
        ->and(session('marmara-designs.oauth.workspace_id'))->toBe($this->workspace->id);
});

test('only workspace admins connect the upload permission', function () {
    $this->actingAs(workspaceMember($this->workspace, 'member'))->get('/designs/drive/connect')->assertForbidden();
});

test('the callback stores the lead\'s tokens encrypted and asks for the folder next', function () {
    // The pending flow as connect() stores it (explicit, so the test does not
    // depend on the session surviving between test requests).
    $state = str_repeat('s', 40);
    $pending = ['marmara-designs.oauth' => ['state' => $state, 'verifier' => str_repeat('v', 64), 'workspace_id' => $this->workspace->id]];

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.lead', 'refresh_token' => '1//refresh', 'expires_in' => 3599, 'scope' => 'https://www.googleapis.com/auth/drive.file', 'token_type' => 'Bearer']),
        'www.googleapis.com/drive/v3/about*' => Http::response(['user' => ['displayName' => 'Yusuf Kulüp', 'emailAddress' => 'lead@example.com']]),
    ]);

    $this->actingAs($this->owner)->withSession($pending)->get("/designs/drive/callback?state={$state}&code=auth-code")
        ->assertRedirect(route('marmara.designs.index', ['drive' => 'pick']));

    $connection = DesignDriveConnection::query()->sole();
    expect($connection->google_email)->toBe('lead@example.com')
        ->and($connection->access_token)->toBe('ya29.lead')
        ->and($connection->refresh_token)->toBe('1//refresh')
        ->and($connection->folder_granted_at)->toBeNull()
        ->and(DB::table('marmara_design_drive_connections')->value('refresh_token'))->not->toContain('1//refresh');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token' && $request['grant_type'] === 'authorization_code' && $request['code_verifier'] === str_repeat('v', 64));
});

test('a callback with a wrong state is refused', function () {
    $this->actingAs($this->owner)
        ->withSession(['marmara-designs.oauth' => ['state' => str_repeat('s', 40), 'verifier' => 'v', 'workspace_id' => $this->workspace->id]])
        ->get('/designs/drive/callback?state=forged&code=x')
        ->assertForbidden();
    $this->actingAs($this->owner)->get('/designs/drive/callback?state=anything&code=x')->assertForbidden();
    expect(DesignDriveConnection::query()->count())->toBe(0);
});

test('granting the folder checks it is the configured folder and that the token can add files to it', function () {
    $connection = designsConnection($this->workspace, ['folder_granted_at' => null]);
    Http::fake([
        'www.googleapis.com/drive/v3/files/'.DESIGNS_ROOT.'*' => Http::response(['id' => DESIGNS_ROOT, 'mimeType' => 'application/vnd.google-apps.folder', 'capabilities' => ['canAddChildren' => true]]),
    ]);

    $this->actingAs($this->owner)->post('/designs/drive/folder', ['folder_id' => 'someOtherFolder'])->assertSessionHasErrors('folder_id');
    expect($connection->fresh()->folder_granted_at)->toBeNull();

    $this->actingAs($this->owner)->post('/designs/drive/folder', ['folder_id' => DESIGNS_ROOT])->assertSessionHasNoErrors();
    expect($connection->fresh()->folder_granted_at)->not->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer lead-access'));
});

test('without the upload permission uploading is refused with a clear turkish message', function () {
    app()->setLocale('tr');

    expect(fn () => app(DesignUploader::class)->upload(UploadedFile::fake()->createWithContent('a.png', designsPng(10, 10)), '', 'Afiş', 'Elif'))
        ->toThrow(DesignUploadUnavailable::class, "Drive'a yükleme izni verilmedi");

    designsConnection($this->workspace, ['folder_granted_at' => null]);
    expect(fn () => app(DesignUploader::class)->upload(UploadedFile::fake()->createWithContent('a.png', designsPng(10, 10)), '', 'Afiş', 'Elif'))
        ->toThrow(DesignUploadUnavailable::class, 'klasör izni eksik');
});

test('an upload goes up with the lead\'s token (their quota) into a new collection folder and is in the gallery at once', function () {
    designsConnection($this->workspace);
    $bytes = designsPng(64, 64);

    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_starts_with($url, 'https://oauth2.googleapis.com/token') => Http::response(['access_token' => 'sa-access-token', 'expires_in' => 3599]),
            $request->method() === 'GET' && str_contains($url, '/drive/v3/files?') => Http::response(['files' => []]),
            $request->method() === 'POST' && str_contains($url, '/upload/drive/v3/files?uploadType=resumable') => Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=abc']),
            $request->method() === 'POST' && str_contains($url, '/drive/v3/files?') => Http::response(['id' => 'fld'.md5((string) data_get($request->data(), 'name')), 'name' => data_get($request->data(), 'name'), 'mimeType' => 'application/vnd.google-apps.folder']),
            $request->method() === 'PUT' => Http::response(designsDriveFile('fileUploaded', 'Ana afiş.png', 'fld'.md5('Afişler'), ['appProperties' => ['trypost_uploader' => 'Elif']])),
            default => Http::response(['error' => ['code' => 404, 'message' => "unexpected {$request->method()} {$url}"]], 404),
        };
    });

    $design = app(DesignUploader::class)->upload(UploadedFile::fake()->createWithContent('poster.png', $bytes), 'Bahar Şenliği/Afişler', 'Ana afiş', 'Elif', $this->owner);

    expect($design)->toBeInstanceOf(Design::class)
        ->and($design->drive_file_id)->toBe('fileUploaded')
        ->and($design->folder_path)->toBe('Bahar Şenliği/Afişler')
        ->and($design->uploader_name)->toBe('Elif');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'uploadType=resumable') && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer lead-access')
            && data_get($request->data(), 'name') === 'Ana afiş.png'
            && data_get($request->data(), 'parents') === ['fld'.md5('Afişler')]
            && data_get($request->data(), 'appProperties') == ['trypost_uploader' => 'Elif'];
    });
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->body() === $bytes);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && ! str_contains($request->url(), '/upload/') && data_get($request->data(), 'name') === 'Bahar Şenliği' && $request->hasHeader('Authorization', 'Bearer lead-access'));
    Queue::assertPushed(SyncDesignRevisions::class);
});

test('a file for an existing member-made folder is uploaded to the shared folder, then moved there by the service account', function () {
    designsConnection($this->workspace);

    Http::fake(function (Request $request) {
        $url = urldecode($request->url());

        return match (true) {
            str_starts_with($url, 'https://oauth2.googleapis.com/token') => Http::response(['access_token' => 'sa-access-token', 'expires_in' => 3599]),
            $request->method() === 'GET' && str_contains($url, "name = 'Afişler'") => Http::response(['files' => [designsDriveFolder('fldExisting', 'Afişler', DESIGNS_ROOT)]]),
            str_contains($url, 'uploadType=resumable') && $request->method() === 'POST' => Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=abc']),
            $request->method() === 'PUT' => Http::response(designsDriveFile('fileUp', 'x.png', DESIGNS_ROOT)),
            $request->method() === 'PATCH' && str_contains($url, '/drive/v3/files/fileUp') => Http::response(designsDriveFile('fileUp', 'x.png', 'fldExisting')),
            default => Http::response(['error' => ['code' => 404, 'message' => 'unexpected']], 404),
        };
    });

    $design = app(DesignUploader::class)->upload(UploadedFile::fake()->createWithContent('x.png', designsPng(8, 8)), 'Afişler', 'x', 'Elif');

    expect($design->folder_path)->toBe('Afişler')->and($design->folder_drive_id)->toBe('fldExisting');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), 'uploadType=resumable') && data_get($request->data(), 'parents') === [DESIGNS_ROOT]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && str_contains(urldecode($request->url()), 'addParents=fldExisting') && str_contains(urldecode($request->url()), 'removeParents='.DESIGNS_ROOT) && $request->hasHeader('Authorization', 'Bearer sa-access-token'));
});

test('an expired lead token is refreshed first, and a revoked one asks to reconnect', function () {
    $connection = designsConnection($this->workspace, ['expires_at' => now()->subMinute()]);
    Http::fake(['oauth2.googleapis.com/token' => Http::sequence()
        ->push(['access_token' => 'lead-new', 'expires_in' => 3599])
        ->push(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);

    expect(app(DriveUploadAuth::class)->accessToken($connection))->toBe('lead-new')
        ->and($connection->fresh()->access_token)->toBe('lead-new');

    $connection->forceFill(['expires_at' => now()->subMinute()])->save();
    expect(fn () => app(DriveUploadAuth::class)->accessToken($connection->fresh()))->toThrow(DesignUploadUnavailable::class);
    expect($connection->fresh()->refresh_token)->toBeNull()
        ->and(app(DesignUploader::class)->available())->toBeFalse();
});

test('a full drive is reported as the lead\'s quota', function () {
    app()->setLocale('en');
    designsConnection($this->workspace);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa', 'expires_in' => 3599]),
        'www.googleapis.com/upload/drive/v3/files*' => Http::response(['error' => ['code' => 403, 'message' => 'The user\'s Drive storage quota has been exceeded.', 'errors' => [['reason' => 'storageQuotaExceeded']]]], 403),
    ]);

    expect(fn () => app(DesignUploader::class)->upload(UploadedFile::fake()->createWithContent('x.png', designsPng(8, 8)), '', 'x', 'Elif'))
        ->toThrow(DesignUploadUnavailable::class, 'storage');
});

test('the page upload refuses unsupported files and shows the uploader message', function () {
    $this->actingAs($this->owner)
        ->post('/designs/upload', ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'hello')])
        ->assertSessionHasErrors('file');
});
