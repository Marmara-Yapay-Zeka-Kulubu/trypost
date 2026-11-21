<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Where the sync of the shared folder stands: the changes.list page token
 * (saved after every page, so a stopped run resumes) and the last outcome.
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $folder_id
 * @property string|null $start_page_token
 * @property string $status
 * @property string|null $status_detail
 * @property CarbonImmutable|null $last_full_sync_at
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $last_attempt_at
 */
class DesignSyncState extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_OK = 'ok';

    public const string STATUS_ERROR = 'error';

    public const string STATUS_RATE_LIMITED = 'rate_limited';

    protected $table = 'marmara_design_sync_states';

    protected $fillable = [
        'workspace_id',
        'folder_id',
        'start_page_token',
        'status',
        'status_detail',
        'last_full_sync_at',
        'last_synced_at',
        'last_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'last_full_sync_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
        ];
    }
}
