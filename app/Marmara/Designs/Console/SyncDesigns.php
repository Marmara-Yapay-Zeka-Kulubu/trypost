<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Console;

use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Sync\DesignSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * Mirrors the shared Drive folder. Scheduled every 5 minutes by the provider
 * (incremental); `--full` walks the whole tree again.
 */
class SyncDesigns extends Command
{
    protected $signature = 'marmara:designs:sync {--full : Walk the whole folder tree instead of reading changes}';

    protected $description = 'Sync the designs gallery with the shared Google Drive folder (Marmara AI fork)';

    public function handle(DesignsConfig $config, DesignSync $sync): int
    {
        if (! $config->enabled()) {
            $this->warn('MARMARA_DESIGNS_ENABLED is off; nothing synced.');

            return self::SUCCESS;
        }

        try {
            $result = $sync->run((bool) $this->option('full'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result->summary());

        return self::SUCCESS;
    }
}
