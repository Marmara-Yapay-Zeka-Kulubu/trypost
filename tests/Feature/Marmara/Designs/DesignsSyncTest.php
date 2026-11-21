<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use App\Marmara\Designs\Jobs\SyncDesignRevisions;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignFolder;
use App\Marmara\Designs\Models\DesignSyncState;
use App\Marmara\Designs\Sync\DesignSync;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([SyncDesignRevisions::class]);
    ['workspace' => $this->workspace] = createApiTestToken();
    designsConfigure($this->workspace);

    $this->tree = [
        DESIGNS_ROOT => [
            designsDriveFolder('fldBahar', 'Bahar Şenliği', DESIGNS_ROOT),
            designsDriveFile('fileLogo', 'Logo.png', DESIGNS_ROOT),
            designsDriveFile('fileNotes', 'Notlar.docx', DESIGNS_ROOT, ['mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']),
        ],
        'fldBahar' => [
            designsDriveFolder('fldAfis', 'Afişler', 'fldBahar'),
            designsDriveFile('fileVideo', 'Tanıtım.mp4', 'fldBahar', ['mimeType' => 'video/mp4', 'imageMediaMetadata' => null, 'videoMediaMetadata' => ['width' => 1920, 'height' => 1080, 'durationMillis' => '15000']]),
        ],
        'fldAfis' => [
            designsDriveFile('filePoster', 'Ana afiş.jpg', 'fldAfis', ['mimeType' => 'image/jpeg']),
            designsDriveFile('fileBrochure', 'Broşür.pdf', 'fldAfis', ['mimeType' => 'application/pdf', 'imageMediaMetadata' => null]),
            designsDriveFile('fileDrawing', 'Kroki', 'fldAfis', ['mimeType' => 'application/vnd.google-apps.drawing', 'size' => null, 'md5Checksum' => null, 'imageMediaMetadata' => null]),
        ],
    ];
});

test('the first sync walks the folder tree, turns folders into collection paths and skips unsupported files', function () {
    designsFakeDrive($this->tree);

    $result = app(DesignSync::class)->run();

    expect($result->mode)->toBe('full')
        ->and($result->added)->toHaveCount(5)
        ->and($result->skipped)->toBe(1);

    $poster = Design::query()->where('drive_file_id', 'filePoster')->sole();
    expect($poster->workspace_id)->toBe($this->workspace->id)
        ->and($poster->title)->toBe('Ana afiş')
        ->and($poster->name)->toBe('Ana afiş.jpg')
        ->and($poster->kind)->toBe('image')
        ->and($poster->folder_path)->toBe('Bahar Şenliği/Afişler')
        ->and($poster->folder_drive_id)->toBe('fldAfis')
        ->and($poster->size)->toBe(48213)
        ->and($poster->md5)->toBe(md5('filePosterAna afiş.jpg'))
        ->and($poster->width)->toBe(1080)
        ->and($poster->height)->toBe(1350)
        ->and($poster->owner_name)->toBe('Yusuf Kulüp')
        ->and($poster->owner_email)->toBe('lead@example.com')
        ->and($poster->modifier_name)->toBe('Elif Tasarım')
        ->and($poster->modifier_email)->toBe('elif@example.com')
        ->and($poster->web_view_link)->toBe('https://drive.google.com/file/d/filePoster/view?usp=drivesdk')
        ->and($poster->drive_created_at->toIso8601String())->toBe('2026-11-20T10:00:00+00:00')
        ->and($poster->status->value)->toBe('draft');

    expect(Design::query()->where('drive_file_id', 'fileVideo')->sole())
        ->kind->toBe('video')->width->toBe(1920)->folder_path->toBe('Bahar Şenliği')
        ->and(Design::query()->where('drive_file_id', 'fileBrochure')->value('kind'))->toBe('pdf')
        ->and(Design::query()->where('drive_file_id', 'fileDrawing')->value('kind'))->toBe('drawing')
        ->and(Design::query()->where('drive_file_id', 'fileNotes')->exists())->toBeFalse()
        ->and(DesignFolder::query()->pluck('path', 'drive_id')->all())->toEqual([DESIGNS_ROOT => '', 'fldBahar' => 'Bahar Şenliği', 'fldAfis' => 'Bahar Şenliği/Afişler']);

    $state = DesignSyncState::query()->sole();
    expect($state->start_page_token)->toBe('token-100')
        ->and($state->status)->toBe('ok')
        ->and($state->last_full_sync_at)->not->toBeNull();

    Queue::assertPushed(SyncDesignRevisions::class, 5);
    Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), "'fldAfis' in parents and trashed = false") && $request->hasHeader('Authorization', 'Bearer sa-access-token'));
});

test('the start page token is asked before the walk, so changes made during it are read next time', function () {
    designsFakeDrive($this->tree);

    app(DesignSync::class)->run();

    $urls = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->url())->values();
    $tokenAt = $urls->search(fn (string $url): bool => str_contains($url, 'changes/startPageToken'));
    $firstList = $urls->search(fn (string $url): bool => str_contains($url, '/drive/v3/files?'));

    expect($tokenAt)->toBeLessThan($firstList);
});

test('an incremental sync applies changes: new files, edits, moves between folders, trash and removal', function () {
    designsFakeDrive($this->tree);
    app(DesignSync::class)->run();

    designsFakeDrive($this->tree, changes: [
        'token-100' => [
            'nextPageToken' => 'token-101',
            'changes' => [
                // Edited in place: new content.
                ['kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'fileLogo', 'removed' => false, 'time' => '2026-11-21T09:00:00Z',
                    'file' => designsDriveFile('fileLogo', 'Logo v2.png', DESIGNS_ROOT, ['md5Checksum' => 'changed', 'headRevisionId' => 'rev-fileLogo-2'])],
                // Moved from Afişler to Bahar Şenliği.
                ['kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'filePoster', 'removed' => false, 'time' => '2026-11-21T09:01:00Z',
                    'file' => designsDriveFile('filePoster', 'Ana afiş.jpg', 'fldBahar', ['mimeType' => 'image/jpeg'])],
            ],
        ],
        'token-101' => [
            'newStartPageToken' => 'token-200',
            'changes' => [
                // Trashed.
                ['kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'fileBrochure', 'removed' => false, 'time' => '2026-11-21T09:02:00Z',
                    'file' => designsDriveFile('fileBrochure', 'Broşür.pdf', 'fldAfis', ['mimeType' => 'application/pdf', 'trashed' => true])],
                // Deleted for good (or unshared): only the id comes.
                ['kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'fileDrawing', 'removed' => true, 'time' => '2026-11-21T09:03:00Z'],
                // New file in the root.
                ['kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'fileNew', 'removed' => false, 'time' => '2026-11-21T09:04:00Z',
                    'file' => designsDriveFile('fileNew', 'Story.webp', DESIGNS_ROOT, ['mimeType' => 'image/webp', 'createdTime' => '2026-11-21T09:04:00Z'])],
                // Moved out of the shared folder.
                ['kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'fileVideo', 'removed' => false, 'time' => '2026-11-21T09:05:00Z',
                    'file' => designsDriveFile('fileVideo', 'Tanıtım.mp4', 'someoneElsesFolder', ['mimeType' => 'video/mp4'])],
                // A file elsewhere in the service account's Drive: ignored.
                ['kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'fileElsewhere', 'removed' => false, 'time' => '2026-11-21T09:06:00Z',
                    'file' => designsDriveFile('fileElsewhere', 'x.png', 'someoneElsesFolder')],
            ],
        ],
    ]);

    $result = app(DesignSync::class)->run();

    expect($result->mode)->toBe('incremental')
        ->and($result->changePages)->toBe(2)
        ->and($result->added)->toHaveCount(1)
        ->and($result->moved)->toBe(1)
        ->and($result->removed)->toBe(3);

    $logo = Design::query()->where('drive_file_id', 'fileLogo')->sole();
    expect($logo->title)->toBe('Logo v2')->and($logo->md5)->toBe('changed')->and($logo->removed_at)->toBeNull();

    expect(Design::query()->where('drive_file_id', 'filePoster')->sole())
        ->folder_path->toBe('Bahar Şenliği')->folder_drive_id->toBe('fldBahar')->removed_at->toBeNull();

    expect(Design::query()->where('drive_file_id', 'fileBrochure')->sole())->removed_reason->toBe('trashed')->removed_at->not->toBeNull()
        ->and(Design::query()->where('drive_file_id', 'fileDrawing')->sole())->removed_reason->toBe('removed')
        ->and(Design::query()->where('drive_file_id', 'fileVideo')->sole())->removed_reason->toBe('moved_out')
        ->and(Design::query()->where('drive_file_id', 'fileNew')->sole())->kind->toBe('image')->folder_path->toBe('')
        ->and(Design::query()->where('drive_file_id', 'fileElsewhere')->exists())->toBeFalse()
        ->and(DesignSyncState::query()->sole()->start_page_token)->toBe('token-200');

    // The edited logo and the new story get their revisions read; the moved poster did not change.
    Queue::assertPushed(SyncDesignRevisions::class, 5 + 2);
});

test('a folder renamed or moved updates the paths below it, and a folder trashed removes its designs', function () {
    designsFakeDrive($this->tree);
    app(DesignSync::class)->run();

    designsFakeDrive($this->tree, changes: [
        'token-100' => [
            'newStartPageToken' => 'token-300',
            'changes' => [
                ['kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'fldBahar', 'removed' => false,
                    'file' => designsDriveFolder('fldBahar', 'Bahar Şenliği 2026', DESIGNS_ROOT)],
            ],
        ],
    ]);
    app(DesignSync::class)->run();

    expect(Design::query()->where('drive_file_id', 'filePoster')->value('folder_path'))->toBe('Bahar Şenliği 2026/Afişler')
        ->and(Design::query()->where('drive_file_id', 'fileVideo')->value('folder_path'))->toBe('Bahar Şenliği 2026');

    designsFakeDrive($this->tree, changes: [
        'token-300' => [
            'newStartPageToken' => 'token-400',
            'changes' => [
                ['kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'fldBahar', 'removed' => false,
                    'file' => [...designsDriveFolder('fldBahar', 'Bahar Şenliği 2026', DESIGNS_ROOT), 'trashed' => true]],
            ],
        ],
    ]);
    app(DesignSync::class)->run();

    expect(Design::query()->active()->pluck('drive_file_id')->all())->toBe(['fileLogo'])
        ->and(DesignFolder::query()->active()->pluck('drive_id')->all())->toBe([DESIGNS_ROOT]);
});

test('a folder moved into the tree is walked, and its files arrive', function () {
    designsFakeDrive($this->tree);
    app(DesignSync::class)->run();

    designsFakeDrive([...$this->tree, 'fldOld' => [designsDriveFile('fileOld', 'Eski afiş.png', 'fldOld')]], changes: [
        'token-100' => [
            'newStartPageToken' => 'token-500',
            'changes' => [
                ['kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'fldOld', 'removed' => false,
                    'file' => designsDriveFolder('fldOld', 'Arşiv 2025', DESIGNS_ROOT)],
            ],
        ],
    ]);
    app(DesignSync::class)->run();

    expect(Design::query()->where('drive_file_id', 'fileOld')->sole()->folder_path)->toBe('Arşiv 2025');
});

test('a page token drive no longer accepts falls back to a full sync, and --full marks vanished files removed', function () {
    designsFakeDrive($this->tree);
    app(DesignSync::class)->run();

    $tree = $this->tree;
    $tree['fldAfis'] = array_values(array_filter($tree['fldAfis'], fn (array $file): bool => $file['id'] !== 'filePoster'));
    designsFakeDrive($tree, changes: [], startToken: 'token-900');

    $result = app(DesignSync::class)->run();

    expect($result->mode)->toBe('full')
        ->and(Design::query()->where('drive_file_id', 'filePoster')->sole()->removed_reason)->toBe('missing')
        ->and(DesignSyncState::query()->sole()->start_page_token)->toBe('token-900');

    $this->artisan('marmara:designs:sync', ['--full' => true])->assertSuccessful();
});

test('the scheduler runs the sync every five minutes only while the module is on', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'marmara:designs:sync'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->filtersPass(app()))->toBeTrue();

    config()->set('marmara-designs.enabled', false);
    expect($event->filtersPass(app()))->toBeFalse();
});

test('marmara:designs:status reports the configuration and counts without calling drive', function () {
    designsFakeDrive($this->tree);
    app(DesignSync::class)->run();
    $requests = count(Http::recorded());

    $this->artisan('marmara:designs:status')
        ->expectsOutputToContain('MARMARA_DESIGNS_ENABLED: on')
        ->expectsOutputToContain('trypost-designs@marmara-test.iam.gserviceaccount.com')
        ->expectsOutputToContain('Designs: 5 active, 0 removed; folders: 3')
        ->assertSuccessful();

    expect(Http::recorded())->toHaveCount($requests);
});
