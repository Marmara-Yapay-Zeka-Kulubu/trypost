<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Upload;

use App\Marmara\Designs\DesignEvents;
use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Drive\DriveClient;
use App\Marmara\Designs\Drive\DriveException;
use App\Marmara\Designs\Jobs\SyncDesignRevisions;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignActivity;
use App\Marmara\Designs\Models\DesignDriveConnection;
use App\Marmara\Designs\Models\DesignFolder;
use App\Marmara\Designs\Support\DesignTypes;
use App\Marmara\Designs\Sync\DesignSync;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Puts a design into the shared folder, for the page now and for the API,
 * MCP and Echo later: DesignUploader::upload($file, 'Bahar Şenliği/Afişler',
 * 'Ana afiş', 'Elif').
 *
 * A service account has no storage quota, so the bytes go up with the
 * connected lead's own drive.file token (the file is the lead's, in the
 * lead's quota) into the shared folder the lead granted in the Picker. The
 * collection path below it is found with the service account (which sees
 * every folder); a missing folder is created with the lead's token (so it is
 * the app's and drive.file may write into it). A file meant for an existing
 * subfolder the app did not create goes into the shared folder first and is
 * then moved there by the service account (Editor), which keeps the lead as
 * the owner.
 */
class DesignUploader
{
    public function __construct(
        private readonly DesignsConfig $config,
        private readonly DriveClient $drive,
        private readonly DriveUploadAuth $auth,
    ) {}

    public function available(): bool
    {
        try {
            $this->connection();
        } catch (DesignUploadUnavailable) {
            return false;
        }

        return true;
    }

    /**
     * @throws DesignUploadUnavailable
     */
    public function upload(UploadedFile|string $file, string $folderPath, string $title, string $uploaderName, ?User $user = null): Design
    {
        $connection = $this->connection();
        $workspace = $connection->workspace;
        $rootId = (string) $this->config->folderId();

        [$bytes, $mime, $originalName] = $this->read($file);

        if (! DesignTypes::supported($mime) || DesignTypes::isGoogleNative($mime)) {
            throw new DesignUploadUnavailable('unsupported');
        }

        if (strlen($bytes) > $this->config->uploadMaxBytes()) {
            throw new DesignUploadUnavailable('too_large', (string) __('marmara-designs::designs.upload.too_large', ['size' => intdiv($this->config->uploadMaxBytes(), 1048576)]));
        }

        $lead = $this->auth->client($connection);
        $uploaderName = Str::limit(trim($uploaderName), 100, '') ?: 'TryPost';

        try {
            [$targetId, $targetPath, $leadCanWrite] = $this->folder($workspace->id, $rootId, $folderPath, $lead);

            $extension = DesignTypes::extensionFor($mime);
            $name = trim($title) === '' ? $originalName : trim($title);
            $name = Str::endsWith(Str::lower($name), '.'.$extension) ? $name : "{$name}.{$extension}";

            $uploaded = $lead->upload([
                'name' => Str::limit($name, 250, ''),
                'parents' => [$leadCanWrite ? $targetId : $rootId],
                'description' => "TryPost üzerinden {$uploaderName} yükledi",
                'appProperties' => ['trypost_uploader' => $uploaderName],
            ], $bytes, $mime);

            if (! $leadCanWrite && $targetId !== $rootId) {
                try {
                    $uploaded = $this->drive->moveFile((string) $uploaded['id'], $targetId, $rootId);
                } catch (DriveException $exception) {
                    $targetId = $rootId;
                    $targetPath = '';
                    Log::warning('Marmara designs: uploaded file stays in the shared folder root', ['status' => $exception->status]);
                }
            }
        } catch (DriveException $exception) {
            throw $this->failure($exception);
        }

        $design = Design::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'drive_file_id' => (string) $uploaded['id']],
            [...DesignSync::attributes($uploaded), 'uploader_name' => $uploaderName, 'folder_drive_id' => $targetId, 'folder_path' => $targetPath, 'removed_at' => null],
        );

        DesignActivity::record($design, $user, 'uploaded', ['name' => $uploaderName]);

        if ($design->wasRecentlyCreated) {
            app(DesignEvents::class)->added($design);
        }
        SyncDesignRevisions::dispatch($design->id);

        return $design;
    }

    /**
     * @throws DesignUploadUnavailable
     */
    public function connection(): DesignDriveConnection
    {
        $workspace = $this->config->workspace();
        $connection = $workspace === null ? null : DesignDriveConnection::query()->where('workspace_id', $workspace->id)->first();

        if ($connection === null || $this->config->folderId() === null || ! $this->config->oauthConfigured()) {
            throw new DesignUploadUnavailable('not_connected');
        }

        if ($connection->refresh_token === null) {
            throw new DesignUploadUnavailable('reconnect');
        }

        if ($connection->folder_granted_at === null) {
            throw new DesignUploadUnavailable('folder_not_granted');
        }

        return $connection;
    }

    /**
     * The target folder for a path like "Bahar Şenliği/Afişler", created as
     * needed, and whether the lead's drive.file token can write into it.
     *
     * @return array{0: string, 1: string, 2: bool}
     */
    private function folder(string $workspaceId, string $rootId, string $path, DriveClient $lead): array
    {
        $segments = array_values(array_filter(array_map(
            fn (string $segment): string => Str::limit(trim(str_replace(['\\', "\0"], '', $segment)), 100, ''),
            explode('/', $path),
        ), fn (string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..'));

        $parentId = $rootId;
        $parentPath = '';
        $leadCanWrite = true;

        foreach ($segments as $segment) {
            $existing = $this->drive->findChildFolder($parentId, $segment);
            $childPath = $parentPath === '' ? $segment : "{$parentPath}/{$segment}";

            if ($existing !== null) {
                // Created by a member in Drive: the lead's drive.file token cannot see it.
                $folderId = (string) $existing['id'];
                $leadCanWrite = false;
            } else {
                $folderId = (string) ($leadCanWrite ? $lead : $this->drive)->createFolder($parentId, $segment)['id'];
            }

            DesignFolder::query()->updateOrCreate(
                ['workspace_id' => $workspaceId, 'drive_id' => $folderId],
                ['parent_drive_id' => $parentId, 'name' => $segment, 'path' => $childPath, 'is_root' => false, 'removed_at' => null],
            );

            $parentId = $folderId;
            $parentPath = $childPath;
        }

        return [$parentId, $parentPath, $leadCanWrite];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function read(UploadedFile|string $file): array
    {
        if ($file instanceof UploadedFile) {
            return [(string) file_get_contents($file->getRealPath() ?: $file->getPathname()), (string) ($file->getMimeType() ?? $file->getClientMimeType()), $file->getClientOriginalName()];
        }

        if (! is_file($file) || ! is_readable($file)) {
            throw new DesignUploadUnavailable('failed', (string) __('marmara-designs::designs.upload.failed', ['detail' => 'file not readable']));
        }

        return [(string) file_get_contents($file), (string) File::mimeType($file), basename($file)];
    }

    private function failure(DriveException $exception): DesignUploadUnavailable
    {
        return match (true) {
            $exception->reason === 'storageQuotaExceeded' => new DesignUploadUnavailable('quota'),
            in_array($exception->status, [401], true) => new DesignUploadUnavailable('reconnect'),
            in_array($exception->status, [403, 404], true) && in_array($exception->reason, ['notFound', 'insufficientFilePermissions', 'appNotAuthorizedToFile'], true) => new DesignUploadUnavailable('folder_not_granted'),
            default => new DesignUploadUnavailable('failed', (string) __('marmara-designs::designs.upload.failed', ['detail' => Str::limit($exception->getMessage(), 160)])),
        };
    }
}
