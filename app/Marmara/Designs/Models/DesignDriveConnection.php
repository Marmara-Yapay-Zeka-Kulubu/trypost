<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Models;

use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The optional "Drive'a yükleme izni": the club lead's Google token with the
 * drive.file scope (encrypted at rest), so uploads land in the lead's Drive
 * quota. `folder_granted_at` is set once the lead picked the shared folder in
 * the Google Picker, which is what lets drive.file write into it.
 *
 * @property int $id
 * @property string $workspace_id
 * @property string|null $user_id
 * @property string|null $google_email
 * @property string|null $google_name
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property CarbonImmutable|null $expires_at
 * @property list<string>|null $scopes
 * @property CarbonImmutable|null $folder_granted_at
 * @property string|null $last_error
 */
class DesignDriveConnection extends Model
{
    protected $table = 'marmara_design_drive_connections';

    protected $fillable = [
        'workspace_id',
        'user_id',
        'google_email',
        'google_name',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
        'folder_granted_at',
        'last_error',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'scopes' => 'array',
            'folder_granted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function usable(): bool
    {
        return $this->refresh_token !== null && $this->folder_granted_at !== null;
    }
}
