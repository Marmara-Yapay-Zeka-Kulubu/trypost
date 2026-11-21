<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Sync;

use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Drive\DriveClient;
use App\Marmara\Designs\Drive\DriveException;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignRevision;
use Carbon\CarbonImmutable;

/**
 * Reads a design's Drive revisions (files.revisions.list) into
 * marmara_design_revisions and marks each one keepForever=true, so Drive
 * keeps it past its default retention (30 days or 100 revisions).
 *
 * keepForever only exists for binary files (not Google Drawings) and Drive
 * keeps at most 200 revisions forever per file; refusals are stored in
 * `keep_error`, never fatal.
 */
class RevisionSync
{
    public const int KEEP_FOREVER_LIMIT = 200;

    public function __construct(
        private readonly DesignsConfig $config,
        private readonly DriveClient $drive,
    ) {}

    public function sync(Design $design): int
    {
        $revisions = $this->drive->revisions($design->drive_file_id);
        $seen = [];
        $kept = DesignRevision::query()->where('design_id', $design->id)->where('keep_forever', true)->count();

        foreach ($revisions as $revision) {
            $id = (string) ($revision['id'] ?? '');

            if ($id === '' || ! DriveClient::validId($id)) {
                continue;
            }

            $seen[] = $id;
            $row = DesignRevision::query()->firstOrNew(['design_id' => $design->id, 'revision_id' => $id]);
            $wasKept = $row->exists && $row->keep_forever;
            $row->fill([
                'mime_type' => $revision['mimeType'] ?? $design->mime_type,
                'size' => isset($revision['size']) && is_numeric($revision['size']) ? (int) $revision['size'] : null,
                'md5' => is_string($revision['md5Checksum'] ?? null) ? $revision['md5Checksum'] : null,
                'modified_at' => is_string($revision['modifiedTime'] ?? null) ? CarbonImmutable::parse($revision['modifiedTime'])->utc() : null,
                'modifier_name' => data_get($revision, 'lastModifyingUser.displayName'),
                'modifier_email' => data_get($revision, 'lastModifyingUser.emailAddress'),
                'original_filename' => is_string($revision['originalFilename'] ?? null) ? mb_substr($revision['originalFilename'], 0, 255) : null,
                'keep_forever' => (bool) ($revision['keepForever'] ?? false),
            ]);

            if (! $row->keep_forever && $this->shouldKeep($design)) {
                if ($kept >= self::KEEP_FOREVER_LIMIT) {
                    $row->keep_error = 'Drive keeps at most 200 revisions forever per file.';
                } else {
                    try {
                        $answer = $this->drive->keepRevisionForever($design->drive_file_id, $id);
                        $row->keep_forever = (bool) ($answer['keepForever'] ?? true);
                        $row->keep_error = null;
                    } catch (DriveException $exception) {
                        if ($exception->isRateLimited()) {
                            $row->save();

                            throw $exception;
                        }

                        $row->keep_error = mb_substr($exception->getMessage(), 0, 255);
                    }
                }
            }

            if ($row->keep_forever && ! $wasKept) {
                $kept++;
            }

            $row->save();
        }

        // Revisions Drive no longer lists (purged before we kept them, or deleted by an editor).
        DesignRevision::query()->where('design_id', $design->id)->whereNotIn('revision_id', $seen)->delete();

        $design->forceFill(['revisions_synced_at' => CarbonImmutable::now()])->save();

        return count($seen);
    }

    private function shouldKeep(Design $design): bool
    {
        return $this->config->keepRevisions() && ! $design->isGoogleNative();
    }
}
