<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Jobs\SyncDesignRevisions;
use App\Marmara\Designs\Models\DesignRevision;
use App\Marmara\Designs\Sync\DesignSync;
use App\Marmara\Designs\Sync\RevisionSync;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    ['workspace' => $this->workspace] = createApiTestToken();
    designsConfigure($this->workspace);
});

/**
 * @return array<string, mixed>
 */
function designsRevision(string $id, string $modified, bool $keepForever = false, array $overrides = []): array
{
    return [
        'kind' => 'drive#revision',
        'id' => $id,
        'mimeType' => 'image/png',
        'modifiedTime' => $modified,
        'keepForever' => $keepForever,
        'size' => '40000',
        'md5Checksum' => md5($id),
        'originalFilename' => "afis-{$id}.png",
        'lastModifyingUser' => ['kind' => 'drive#user', 'displayName' => 'Elif Tasarım', 'emailAddress' => 'elif@example.com'],
        ...$overrides,
    ];
}

test('revisions are stored and every one not yet kept is marked keepForever', function () {
    $design = designsMake($this->workspace, ['drive_file_id' => 'filePoster']);
    designsFakeDrive(revisions: ['filePoster' => [
        designsRevision('r1', '2026-11-01T10:00:00Z'),
        designsRevision('r2', '2026-11-05T10:00:00Z', keepForever: true),
        designsRevision('r3', '2026-11-09T10:00:00Z'),
    ]]);

    expect(app(RevisionSync::class)->sync($design))->toBe(3);

    $revisions = DesignRevision::query()->where('design_id', $design->id)->orderBy('modified_at')->get();
    expect($revisions->pluck('revision_id')->all())->toBe(['r1', 'r2', 'r3'])
        ->and($revisions->every(fn (DesignRevision $revision): bool => $revision->keep_forever))->toBeTrue()
        ->and($revisions->first()->modifier_name)->toBe('Elif Tasarım')
        ->and($revisions->first()->original_filename)->toBe('afis-r1.png')
        ->and($design->fresh()->revisions_synced_at)->not->toBeNull();

    $patched = collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'PATCH');
    expect($patched)->toHaveCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && str_contains($request->url(), '/drive/v3/files/filePoster/revisions/r1')
        && $request->data() === ['keepForever' => true]);
});

test('a revision drive purged is dropped, and kept ones are not patched again', function () {
    $design = designsMake($this->workspace, ['drive_file_id' => 'fileLogo']);
    DesignRevision::query()->create(['design_id' => $design->id, 'revision_id' => 'gone', 'keep_forever' => false]);
    designsFakeDrive(revisions: ['fileLogo' => [designsRevision('r9', '2026-11-10T10:00:00Z', keepForever: true)]]);

    app(RevisionSync::class)->sync($design);

    expect(DesignRevision::query()->where('design_id', $design->id)->pluck('revision_id')->all())->toBe(['r9']);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
});

test('google drawings are listed but never patched: keepForever exists only for binary files', function () {
    $design = designsMake($this->workspace, ['drive_file_id' => 'fileDrawing', 'mime_type' => 'application/vnd.google-apps.drawing', 'kind' => 'drawing']);
    designsFakeDrive(revisions: ['fileDrawing' => [designsRevision('d1', '2026-11-10T10:00:00Z', overrides: ['mimeType' => 'application/vnd.google-apps.drawing'])]]);

    app(RevisionSync::class)->sync($design);

    expect(DesignRevision::query()->where('design_id', $design->id)->sole()->keep_forever)->toBeFalse();
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
});

test('a refused keepForever (no edit access) is recorded on the revision and does not stop the sync', function () {
    $design = designsMake($this->workspace, ['drive_file_id' => 'fileViewer']);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa', 'expires_in' => 3599]),
        'www.googleapis.com/drive/v3/files/fileViewer/revisions/r1*' => Http::response(['error' => ['code' => 403, 'message' => 'The user does not have sufficient permissions for this file.', 'errors' => [['reason' => 'insufficientFilePermissions']]]], 403),
        'www.googleapis.com/drive/v3/files/fileViewer/revisions*' => Http::response(['revisions' => [designsRevision('r1', '2026-11-10T10:00:00Z')]]),
    ]);

    app(RevisionSync::class)->sync($design);

    $revision = DesignRevision::query()->where('design_id', $design->id)->sole();
    expect($revision->keep_forever)->toBeFalse()
        ->and($revision->keep_error)->toContain('sufficient permissions');
});

test('with keep_revisions off nothing is patched', function () {
    config()->set('marmara-designs.keep_revisions', false);
    $design = designsMake($this->workspace, ['drive_file_id' => 'filePoster']);
    designsFakeDrive(revisions: ['filePoster' => [designsRevision('r1', '2026-11-01T10:00:00Z')]]);

    app(RevisionSync::class)->sync($design);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
});

test('the sync queues the revision job, which reads and keeps the revisions of a new file', function () {
    designsFakeDrive(
        [DESIGNS_ROOT => [designsDriveFile('fileNew', 'Yeni.png', DESIGNS_ROOT)]],
        revisions: ['fileNew' => [designsRevision('n1', '2026-11-20T10:00:00Z'), designsRevision('n2', '2026-11-20T10:05:00Z')]],
    );

    app(DesignSync::class)->run();

    expect(DesignRevision::query()->pluck('revision_id')->sort()->values()->all())->toBe(['n1', 'n2'])
        ->and(DesignRevision::query()->where('keep_forever', true)->count())->toBe(2);
});

test('the revision job skips designs that were removed in the meantime', function () {
    $design = designsMake($this->workspace, ['removed_at' => now()]);
    designsFakeDrive();

    (new SyncDesignRevisions($design->id))->handle(app(DesignsConfig::class), app(RevisionSync::class));

    Http::assertNothingSent();
});
