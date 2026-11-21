<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Console;

use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Drive\DriveClient;
use App\Marmara\Designs\Drive\DriveException;
use App\Marmara\Designs\Drive\ServiceAccountToken;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignDriveConnection;
use App\Marmara\Designs\Models\DesignFolder;
use App\Marmara\Designs\Models\DesignRevision;
use App\Marmara\Designs\Models\DesignSyncState;
use App\Marmara\Designs\Models\DesignThumbnail;
use Illuminate\Console\Command;

/**
 * The module's configuration, sync state and counts. `--check` also asks
 * Drive for a token and the folder (two requests); without it no network call
 * is made. Keys and tokens are never printed.
 */
class DesignsStatus extends Command
{
    protected $signature = 'marmara:designs:status {--check : Also check the service account token and folder access on Drive}';

    protected $description = 'Show the designs gallery configuration, Drive sync state and counts (Marmara AI fork)';

    public function handle(DesignsConfig $config, ServiceAccountToken $token, DriveClient $drive): int
    {
        $workspace = $config->workspace();
        $folderId = $config->folderId();

        $this->line('MARMARA_DESIGNS_ENABLED: '.($config->enabled() ? 'on' : 'off'));
        $this->line('Workspace: '.($workspace ? "{$workspace->name} ({$workspace->id})" : 'none (set MARMARA_DESIGNS_WORKSPACE_ID)'));
        $this->line('Folder: '.($folderId ?? 'not set (MARMARA_DESIGNS_FOLDER_ID)'));
        $this->line('Service account key: '.($config->serviceAccountKeyPath() ?? 'not set (MARMARA_DESIGNS_SA_KEY)'));

        try {
            $this->line('Service account: '.$token->key()['client_email'].' (share the folder with it as Editor)');
        } catch (DriveException $exception) {
            $this->warn('Service account: '.$exception->getMessage());
        }

        if ($workspace !== null && $folderId !== null) {
            $state = DesignSyncState::query()->where('workspace_id', $workspace->id)->where('folder_id', $folderId)->first();

            $this->line('Sync: '.($state === null ? 'never run' : sprintf(
                '%s, last full %s, last run %s, changes token %s%s',
                $state->status,
                $state->last_full_sync_at?->toIso8601String() ?? 'never',
                $state->last_synced_at?->toIso8601String() ?? 'never',
                $state->start_page_token === null ? 'none' : 'stored',
                $state->status_detail ? " ({$state->status_detail})" : '',
            )));

            $designs = Design::query()->where('workspace_id', $workspace->id);
            $this->line(sprintf(
                'Designs: %d active, %d removed; folders: %d; revisions: %d (%d kept forever, %d refused)',
                (clone $designs)->active()->count(),
                (clone $designs)->whereNotNull('removed_at')->count(),
                DesignFolder::query()->where('workspace_id', $workspace->id)->active()->count(),
                DesignRevision::query()->whereIn('design_id', (clone $designs)->select('id'))->count(),
                DesignRevision::query()->whereIn('design_id', (clone $designs)->select('id'))->where('keep_forever', true)->count(),
                DesignRevision::query()->whereIn('design_id', (clone $designs)->select('id'))->whereNotNull('keep_error')->count(),
            ));
        }

        $this->line(sprintf(
            'Thumbnail cache: %.1f MB of %d MB in %s (%d files)',
            ((int) DesignThumbnail::query()->sum('bytes')) / 1048576,
            intdiv($config->thumbnailCacheMaxBytes(), 1048576),
            $config->thumbnailPath(),
            DesignThumbnail::query()->count(),
        ));

        $connection = $workspace === null ? null : DesignDriveConnection::query()->where('workspace_id', $workspace->id)->first();
        $this->line('Drive uploads: '.match (true) {
            ! $config->oauthConfigured() => 'off (MARMARA_DESIGNS_OAUTH_CLIENT_ID / _SECRET not set)',
            $connection === null => 'not connected',
            $connection->refresh_token === null => 'needs reconnecting ('.($connection->last_error ?? 'no token').')',
            $connection->folder_granted_at === null => "connected as {$connection->google_email}, folder not granted yet",
            default => "connected as {$connection->google_email}, folder granted",
        });

        if (! $this->option('check')) {
            return self::SUCCESS;
        }

        try {
            $token->accessToken();
            $this->info('Drive token: ok');

            if ($folderId !== null) {
                $folder = $drive->file($folderId, 'id,name,mimeType,capabilities(canEdit,canAddChildren)');
                $this->info("Folder: \"{$folder['name']}\" readable; can edit: ".(data_get($folder, 'capabilities.canEdit') ? 'yes' : 'no (share it as Editor: keepForever needs it)'));
            }
        } catch (DriveException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
