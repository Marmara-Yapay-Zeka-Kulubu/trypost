<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use App\Enums\Webhook\EventType;
use App\Jobs\DispatchWebhook;
use App\Marmara\Designs\Actions\DesignActions;
use App\Marmara\Designs\Jobs\SyncDesignRevisions;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignActivity;
use App\Marmara\Designs\Models\DesignFolder;
use App\Marmara\Designs\Models\DesignSyncState;
use App\Marmara\Designs\Support\DesignStatus;
use App\Marmara\Designs\Sync\DesignSync;
use App\Models\Webhook;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([DispatchWebhook::class, SyncDesignRevisions::class]);
    ['workspace' => $this->workspace, 'user' => $this->owner] = createApiTestToken();
    designsConfigure($this->workspace);
    Webhook::factory()->create(['workspace_id' => $this->workspace->id, 'events' => [EventType::DesignStatusChanged->value]]);

    // A synced folder: the root is known and the changes token stored.
    DesignFolder::query()->create(['workspace_id' => $this->workspace->id, 'drive_id' => DESIGNS_ROOT, 'name' => 'Tasarımlar', 'path' => '', 'is_root' => true]);
    DesignSyncState::query()->create(['workspace_id' => $this->workspace->id, 'folder_id' => DESIGNS_ROOT, 'start_page_token' => 'token-100', 'status' => 'ok', 'last_full_sync_at' => now()]);

    $this->design = designsMake($this->workspace, ['drive_file_id' => 'filePoster', 'name' => 'Afiş.png', 'md5' => 'md5-approved']);
});

/**
 * One changes.list page with the poster changed (or only renamed).
 *
 * @param  array<string, mixed>  $overrides
 */
function designsChangePoster(array $overrides): void
{
    designsFakeDrive(changes: ['token-100' => [
        'newStartPageToken' => 'token-101',
        'changes' => [[
            'kind' => 'drive#change', 'changeType' => 'file', 'fileId' => 'filePoster', 'removed' => false,
            'file' => designsDriveFile('filePoster', 'Afiş.png', DESIGNS_ROOT, ['md5Checksum' => 'md5-approved', ...$overrides]),
        ]],
    ]]);
}

test('an approved design whose file changes goes back to review, with an activity line and a webhook', function () {
    app(DesignActions::class)->setStatus($this->design, $this->owner, DesignStatus::Approved);
    expect($this->design->fresh()->approved_version)->toBe('md5-approved');
    Queue::fake([DispatchWebhook::class, SyncDesignRevisions::class]);

    designsChangePoster(['md5Checksum' => 'md5-edited', 'headRevisionId' => 'rev-2']);
    app(DesignSync::class)->run();

    $design = $this->design->fresh();
    expect($design->status)->toBe(DesignStatus::InReview)
        ->and($design->status_reason)->toBe('content_changed')
        ->and($design->approved_version)->toBeNull()
        ->and(DesignActivity::query()->where('design_id', $design->id)->where('type', 'content_changed')->sole()->data)->toEqual(['from' => 'approved', 'to' => 'in_review']);

    Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === 'design.status_changed'
        && $job->payload['status']['from'] === 'approved'
        && $job->payload['status']['to'] === 'in_review'
        && $job->payload['status']['reason'] === 'content_changed'
        && $job->payload['status']['changed_by'] === null
        && $job->payload['design']['status_reason'] === 'content_changed');
});

test('a rename or a move without new content keeps the approval', function () {
    app(DesignActions::class)->setStatus($this->design, $this->owner, DesignStatus::Approved);
    Queue::fake([DispatchWebhook::class, SyncDesignRevisions::class]);

    designsChangePoster(['name' => 'Afiş son hali.png', 'modifiedTime' => '2026-11-21T08:00:00.000Z']);
    app(DesignSync::class)->run();

    expect($this->design->fresh())->status->toBe(DesignStatus::Approved)->title->toBe('Afiş son hali');
    Queue::assertNotPushed(DispatchWebhook::class);
});

test('a used design stays used when its file changes, flagged as changed after use, without a webhook', function () {
    app(DesignActions::class)->setStatus($this->design, $this->owner, DesignStatus::Used);
    Queue::fake([DispatchWebhook::class, SyncDesignRevisions::class]);

    designsChangePoster(['md5Checksum' => 'md5-edited']);
    app(DesignSync::class)->run();

    $design = $this->design->fresh();
    expect($design->status)->toBe(DesignStatus::Used)
        ->and($design->changed_after_use_at)->not->toBeNull()
        ->and($design->approved_version)->toBe('md5-edited')
        ->and(DesignActivity::query()->where('design_id', $design->id)->where('type', 'changed_after_use')->count())->toBe(1);
    Queue::assertNotPushed(DispatchWebhook::class);

    // The page shows the flag on the card and in the drawer.
    $this->actingAs($this->owner)->get("/designs?design={$design->id}")
        ->assertInertia(fn ($page) => $page
            ->where('designs.0.changed_after_use_at', $design->changed_after_use_at->toIso8601String())
            ->where('selected.status', 'used'));
});

test('setting a status by hand clears the automatic reason and stores the approved content again', function () {
    $this->design->forceFill(['status' => DesignStatus::InReview, 'status_reason' => 'content_changed'])->save();

    app(DesignActions::class)->setStatus($this->design, $this->owner, DesignStatus::Approved);

    expect(Design::query()->find($this->design->id))
        ->status_reason->toBeNull()
        ->approved_version->toBe('md5-approved');
});
