<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Sync;

/**
 * What one sync run did, for the command output and the tests.
 */
final class SyncResult
{
    /** @var list<string> ids of designs seen for the first time */
    public array $added = [];

    public int $updated = 0;

    public int $removed = 0;

    public int $moved = 0;

    public int $skipped = 0;

    public int $folders = 0;

    public int $changePages = 0;

    public function __construct(public readonly string $mode) {}

    public function summary(): string
    {
        return sprintf(
            '%s sync: %d added, %d updated, %d moved, %d removed, %d skipped (unsupported), %d folders%s',
            $this->mode,
            count($this->added),
            $this->updated,
            $this->moved,
            $this->removed,
            $this->skipped,
            $this->folders,
            $this->mode === 'incremental' ? ", {$this->changePages} change pages" : '',
        );
    }
}
