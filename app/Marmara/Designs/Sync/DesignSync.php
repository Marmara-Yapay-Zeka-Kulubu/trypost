<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Sync;

use App\Marmara\Designs\DesignEvents;
use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Drive\DriveClient;
use App\Marmara\Designs\Drive\DriveException;
use App\Marmara\Designs\Jobs\SyncDesignRevisions;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignActivity;
use App\Marmara\Designs\Models\DesignFolder;
use App\Marmara\Designs\Models\DesignSyncState;
use App\Marmara\Designs\Support\DesignTypes;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Mirrors the shared Drive folder into marmara_design_folders and
 * marmara_designs.
 *
 * - Full (first run, `--full`, or a page token Drive no longer accepts):
 *   asks for a startPageToken first (so nothing changed during the walk is
 *   missed), walks the tree breadth first, then marks every row it did not
 *   see as removed.
 * - Incremental: changes.list from the stored token, page by page (the token
 *   is saved after each page). A trashed or removed file, or one moved out of
 *   the tree, is marked removed; a move inside the tree updates its folder;
 *   a folder that appears (created, or moved in from outside) is walked.
 *
 * The service account only sees what is shared with it, so changes outside
 * the folder are rare; they are ignored unless the file was ours.
 */
class DesignSync
{
    public const string MODE_FULL = 'full';

    public const string MODE_INCREMENTAL = 'incremental';

    public function __construct(
        private readonly DesignsConfig $config,
        private readonly DriveClient $drive,
        private readonly DesignEvents $events,
    ) {}

    public function run(bool $full = false): SyncResult
    {
        $workspace = $this->config->workspace();
        $folderId = $this->config->folderId();

        if ($workspace === null) {
            throw new RuntimeException('No workspace for the designs: set MARMARA_DESIGNS_WORKSPACE_ID.');
        }

        if ($folderId === null || ! DriveClient::validId($folderId)) {
            throw new RuntimeException('MARMARA_DESIGNS_FOLDER_ID is not set or not a Drive id.');
        }

        try {
            return Cache::lock("marmara-designs:sync:{$workspace->id}", 900)->block(5, fn (): SyncResult => $this->locked($workspace, $folderId, $full));
        } catch (LockTimeoutException) {
            throw new RuntimeException('Another designs sync is running.');
        }
    }

    private function locked(Workspace $workspace, string $folderId, bool $full): SyncResult
    {
        $state = DesignSyncState::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'folder_id' => $folderId],
            ['status' => DesignSyncState::STATUS_PENDING],
        );
        $state->forceFill(['last_attempt_at' => CarbonImmutable::now()])->save();

        try {
            $result = $full || $state->start_page_token === null
                ? $this->full($workspace, $folderId, $state)
                : $this->incremental($workspace, $folderId, $state);
        } catch (DriveException $exception) {
            $state->forceFill([
                'status' => $exception->isRateLimited() ? DesignSyncState::STATUS_RATE_LIMITED : DesignSyncState::STATUS_ERROR,
                'status_detail' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        $state->forceFill([
            'status' => DesignSyncState::STATUS_OK,
            'status_detail' => $result->summary(),
            'last_synced_at' => CarbonImmutable::now(),
        ])->save();

        return $result;
    }

    private function full(Workspace $workspace, string $folderId, DesignSyncState $state): SyncResult
    {
        $result = new SyncResult(self::MODE_FULL);
        // The first full sync reads the folder's backlog: no design.added for it.
        $notify = $state->last_full_sync_at !== null;
        $token = $this->drive->startPageToken();
        $rootFile = $this->drive->file($folderId, 'id,name,mimeType');

        $root = $this->upsertFolder($workspace, $folderId, null, (string) ($rootFile['name'] ?? ''), '', true);
        $seenFolders = [$root->drive_id];
        $seenFiles = [];

        $this->walk($workspace, $root, $result, $notify, $seenFolders, $seenFiles);

        DesignFolder::query()->where('workspace_id', $workspace->id)->active()->whereNotIn('drive_id', $seenFolders)
            ->update(['removed_at' => CarbonImmutable::now()]);
        $result->removed += Design::query()->where('workspace_id', $workspace->id)->active()->whereNotIn('drive_file_id', $seenFiles)
            ->update(['removed_at' => CarbonImmutable::now(), 'removed_reason' => 'missing']);

        $state->forceFill(['start_page_token' => $token, 'last_full_sync_at' => CarbonImmutable::now()])->save();

        return $result;
    }

    private function incremental(Workspace $workspace, string $folderId, DesignSyncState $state): SyncResult
    {
        $result = new SyncResult(self::MODE_INCREMENTAL);
        $pageToken = (string) $state->start_page_token;

        for ($page = 0; $page < $this->config->maxChangePages(); $page++) {
            try {
                $changes = $this->drive->changes($pageToken);
            } catch (DriveException $exception) {
                if (in_array($exception->status, [400, 404, 410], true)) {
                    // The stored token expired or is no longer valid: start over.
                    return $this->full($workspace, $folderId, $state);
                }

                throw $exception;
            }

            $result->changePages++;

            foreach ($changes['changes'] as $change) {
                $this->apply($workspace, $folderId, $change, $result);
            }

            $pageToken = $changes['nextPageToken'] ?? $changes['newStartPageToken'] ?? $pageToken;
            $state->forceFill(['start_page_token' => $pageToken])->save();

            if ($changes['nextPageToken'] === null) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function apply(Workspace $workspace, string $rootId, array $change, SyncResult $result): void
    {
        $fileId = (string) ($change['fileId'] ?? data_get($change, 'file.id', ''));

        if ($fileId === '' || ($change['changeType'] ?? 'file') === 'drive') {
            return;
        }

        $file = is_array($change['file'] ?? null) ? $change['file'] : null;

        if ((bool) ($change['removed'] ?? false) || $file === null || (bool) ($file['trashed'] ?? false)) {
            $this->removeById($workspace, $fileId, (bool) ($change['removed'] ?? false) || $file === null ? 'removed' : 'trashed', $result);

            return;
        }

        if ($fileId === $rootId) {
            DesignFolder::query()->where('workspace_id', $workspace->id)->where('drive_id', $rootId)->update(['name' => (string) ($file['name'] ?? '')]);

            return;
        }

        $parent = $this->parentFolder($workspace, (array) ($file['parents'] ?? []));

        if (($file['mimeType'] ?? null) === DriveClient::FOLDER_MIME) {
            $this->applyFolder($workspace, $file, $parent, $result);

            return;
        }

        if ($parent === null || ! DesignTypes::supported($file['mimeType'] ?? null)) {
            if (Design::query()->where('workspace_id', $workspace->id)->where('drive_file_id', $fileId)->active()->exists()) {
                $this->removeById($workspace, $fileId, $parent === null ? 'moved_out' : 'unsupported', $result);
            } elseif ($parent !== null) {
                $result->skipped++;
            }

            return;
        }

        $this->upsertDesign($workspace, $file, $parent, $result, true);
    }

    /**
     * @param  array<string, mixed>  $file
     */
    private function applyFolder(Workspace $workspace, array $file, ?DesignFolder $parent, SyncResult $result): void
    {
        $id = (string) $file['id'];
        $known = DesignFolder::query()->where('workspace_id', $workspace->id)->where('drive_id', $id)->active()->first();

        if ($parent === null) {
            if ($known !== null) {
                $this->removeFolder($workspace, $known, 'moved_out', $result);
            }

            return;
        }

        $name = (string) ($file['name'] ?? '');
        $folder = $this->upsertFolder($workspace, $id, $parent->drive_id, $name, $parent->childPath($name), false);

        if ($known === null) {
            // New, restored or moved in from outside the tree: its content
            // never came through changes, so read it.
            $result->folders++;
            $seenFolders = [];
            $seenFiles = [];
            $this->walk($workspace, $folder, $result, true, $seenFolders, $seenFiles);

            return;
        }

        if ($known->path !== $folder->path) {
            $this->repath($workspace, $folder, $result);
        }
    }

    /**
     * Breadth-first walk below a folder.
     *
     * @param  list<string>  $seenFolders
     * @param  list<string>  $seenFiles
     */
    private function walk(Workspace $workspace, DesignFolder $start, SyncResult $result, bool $notify, array &$seenFolders, array &$seenFiles): void
    {
        $queue = [$start];

        while ($queue !== []) {
            $folder = array_shift($queue);
            $pageToken = null;

            do {
                $page = $this->drive->children($folder->drive_id, $pageToken);

                foreach ($page['files'] as $file) {
                    $id = (string) ($file['id'] ?? '');

                    if ($id === '') {
                        continue;
                    }

                    if (($file['mimeType'] ?? null) === DriveClient::FOLDER_MIME) {
                        $name = (string) ($file['name'] ?? '');
                        $child = $this->upsertFolder($workspace, $id, $folder->drive_id, $name, $folder->childPath($name), false);
                        $seenFolders[] = $id;
                        $result->folders++;
                        $queue[] = $child;

                        continue;
                    }

                    if (! DesignTypes::supported($file['mimeType'] ?? null)) {
                        $result->skipped++;

                        continue;
                    }

                    $seenFiles[] = $id;
                    $this->upsertDesign($workspace, $file, $folder, $result, $notify);
                }

                $pageToken = $page['nextPageToken'];
            } while ($pageToken !== null);
        }
    }

    /**
     * @param  array<string, mixed>  $file
     */
    private function upsertDesign(Workspace $workspace, array $file, DesignFolder $folder, SyncResult $result, bool $notify): Design
    {
        $design = Design::query()->firstOrNew(['workspace_id' => $workspace->id, 'drive_file_id' => (string) $file['id']]);
        $isNew = ! $design->exists;
        $previousFolder = $design->folder_drive_id;
        $previousVersion = $design->exists ? $design->contentVersion() : null;

        $design->fill([
            ...self::attributes($file),
            'folder_drive_id' => $folder->drive_id,
            'folder_path' => $folder->path,
            'removed_at' => null,
            'removed_reason' => null,
        ]);
        $design->save();

        $contentChanged = $isNew || $previousVersion !== $design->contentVersion();

        if ($isNew) {
            $result->added[] = $design->id;
            $this->onAdded($design, $notify);
        } else {
            $result->updated++;

            if ($previousFolder !== null && $previousFolder !== $folder->drive_id) {
                $result->moved++;
            }
        }

        if ($contentChanged) {
            SyncDesignRevisions::dispatch($design->id);
        }

        return $design;
    }

    /**
     * A file seen for the first time: one activity line, and the design.added
     * webhook unless it is the first sync's backlog or older than
     * MARMARA_DESIGNS_NOTIFY_MAX_AGE_HOURS.
     */
    private function onAdded(Design $design, bool $notify): void
    {
        DesignActivity::record($design, null, 'added');

        $since = CarbonImmutable::now()->subHours($this->config->notifyMaxAgeHours());

        if ($notify && ($design->drive_created_at === null || $design->drive_created_at->greaterThanOrEqualTo($since))) {
            $this->events->added($design);
        }
    }

    private function upsertFolder(Workspace $workspace, string $driveId, ?string $parentId, string $name, string $path, bool $isRoot): DesignFolder
    {
        $folder = DesignFolder::query()->firstOrNew(['workspace_id' => $workspace->id, 'drive_id' => $driveId]);
        $folder->fill([
            'parent_drive_id' => $parentId,
            'name' => $name,
            'path' => $path,
            'is_root' => $isRoot,
            'removed_at' => null,
        ])->save();

        return $folder;
    }

    /**
     * @param  list<mixed>  $parents
     */
    private function parentFolder(Workspace $workspace, array $parents): ?DesignFolder
    {
        $ids = array_values(array_filter($parents, 'is_string'));

        if ($ids === []) {
            return null;
        }

        return DesignFolder::query()->where('workspace_id', $workspace->id)->active()->whereIn('drive_id', $ids)->first();
    }

    private function removeById(Workspace $workspace, string $fileId, string $reason, SyncResult $result): void
    {
        $folder = DesignFolder::query()->where('workspace_id', $workspace->id)->where('drive_id', $fileId)->active()->first();

        if ($folder !== null && ! $folder->is_root) {
            $this->removeFolder($workspace, $folder, $reason, $result);

            return;
        }

        $result->removed += Design::query()->where('workspace_id', $workspace->id)->where('drive_file_id', $fileId)->active()
            ->update(['removed_at' => CarbonImmutable::now(), 'removed_reason' => $reason]);
    }

    private function removeFolder(Workspace $workspace, DesignFolder $folder, string $reason, SyncResult $result): void
    {
        $ids = $this->descendantIds($workspace, $folder->drive_id);

        DesignFolder::query()->where('workspace_id', $workspace->id)->whereIn('drive_id', $ids)->update(['removed_at' => CarbonImmutable::now()]);
        $result->removed += Design::query()->where('workspace_id', $workspace->id)->whereIn('folder_drive_id', $ids)->active()
            ->update(['removed_at' => CarbonImmutable::now(), 'removed_reason' => $reason]);
    }

    /**
     * A folder renamed or moved inside the tree: new paths below it.
     */
    private function repath(Workspace $workspace, DesignFolder $folder, SyncResult $result): void
    {
        $queue = [$folder];

        while ($queue !== []) {
            $current = array_shift($queue);
            $result->moved += Design::query()->where('workspace_id', $workspace->id)->where('folder_drive_id', $current->drive_id)
                ->update(['folder_path' => $current->path]);

            DesignFolder::query()->where('workspace_id', $workspace->id)->where('parent_drive_id', $current->drive_id)->active()->get()
                ->each(function (DesignFolder $child) use ($current, &$queue): void {
                    $child->forceFill(['path' => $current->childPath($child->name)])->save();
                    $queue[] = $child;
                });
        }
    }

    /**
     * @return list<string>
     */
    private function descendantIds(Workspace $workspace, string $folderId): array
    {
        $ids = [$folderId];
        $frontier = [$folderId];

        while ($frontier !== []) {
            $frontier = DesignFolder::query()->where('workspace_id', $workspace->id)->whereIn('parent_drive_id', $frontier)
                ->whereNotIn('drive_id', $ids)->pluck('drive_id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    /**
     * The stored columns of a Drive file resource.
     *
     * @param  array<string, mixed>  $file
     * @return array<string, mixed>
     */
    public static function attributes(array $file): array
    {
        $name = (string) ($file['name'] ?? '');
        $mime = (string) ($file['mimeType'] ?? 'application/octet-stream');
        $media = is_array($file['imageMediaMetadata'] ?? null) ? $file['imageMediaMetadata'] : (is_array($file['videoMediaMetadata'] ?? null) ? $file['videoMediaMetadata'] : []);
        $uploader = data_get($file, 'appProperties.trypost_uploader');

        return [
            'name' => mb_substr($name, 0, 255),
            'title' => mb_substr(DesignTypes::isGoogleNative($mime) ? $name : (string) preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $name), 0, 255) ?: mb_substr($name, 0, 255),
            'mime_type' => $mime,
            'kind' => DesignTypes::kindFor($mime) ?? DesignTypes::IMAGE,
            'size' => isset($file['size']) && is_numeric($file['size']) ? (int) $file['size'] : null,
            'md5' => is_string($file['md5Checksum'] ?? null) ? $file['md5Checksum'] : null,
            'drive_created_at' => self::time($file['createdTime'] ?? null),
            'drive_modified_at' => self::time($file['modifiedTime'] ?? null),
            'owner_name' => self::limit(data_get($file, 'owners.0.displayName')),
            'owner_email' => self::limit(data_get($file, 'owners.0.emailAddress')),
            'modifier_name' => self::limit(data_get($file, 'lastModifyingUser.displayName')),
            'modifier_email' => self::limit(data_get($file, 'lastModifyingUser.emailAddress')),
            'uploader_name' => self::limit($uploader),
            'web_view_link' => is_string($file['webViewLink'] ?? null) ? $file['webViewLink'] : null,
            'width' => isset($media['width']) && is_numeric($media['width']) ? (int) $media['width'] : null,
            'height' => isset($media['height']) && is_numeric($media['height']) ? (int) $media['height'] : null,
            'head_revision_id' => is_string($file['headRevisionId'] ?? null) ? $file['headRevisionId'] : null,
        ];
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }

    private static function limit(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 255) : null;
    }
}
