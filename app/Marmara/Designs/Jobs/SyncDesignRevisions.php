<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Jobs;

use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Drive\DriveException;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Sync\RevisionSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reads one design's revisions and keeps them forever on Drive. Queued by
 * the sync whenever a file is new or its content changed.
 */
class SyncDesignRevisions implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $designId) {}

    public function uniqueId(): string
    {
        return $this->designId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(DesignsConfig $config, RevisionSync $revisions): void
    {
        $design = Design::query()->find($this->designId);

        if ($design === null || $design->removed_at !== null || ! $config->enabled()) {
            return;
        }

        try {
            $revisions->sync($design);
        } catch (DriveException $exception) {
            if ($exception->isRateLimited()) {
                $this->release(300);

                return;
            }

            Log::warning('Marmara designs: revisions could not be read', ['design' => $design->id, 'status' => $exception->status, 'reason' => $exception->reason]);
        }
    }
}
