<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Models;

use App\Marmara\Designs\Support\DesignStatus;
use App\Marmara\Designs\Support\DesignTypes;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One supported file of the shared Drive folder, with the club's own data on
 * it (status, tags, comments, votes). Files linked by hand as versions of each
 * other share a `family_id` (the id of the family's first design); the
 * gallery shows only the newest of a family (`family_latest`).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $drive_file_id
 * @property string $name
 * @property string $title
 * @property string $mime_type
 * @property string $kind
 * @property int|null $size
 * @property string|null $md5
 * @property string|null $folder_drive_id
 * @property string $folder_path
 * @property CarbonImmutable|null $drive_created_at
 * @property CarbonImmutable|null $drive_modified_at
 * @property string|null $owner_name
 * @property string|null $owner_email
 * @property string|null $modifier_name
 * @property string|null $modifier_email
 * @property string|null $uploader_name
 * @property string|null $web_view_link
 * @property int|null $width
 * @property int|null $height
 * @property string|null $head_revision_id
 * @property DesignStatus $status
 * @property CarbonImmutable|null $status_changed_at
 * @property string|null $family_id
 * @property bool $family_latest
 * @property int $votes_count
 * @property int $comments_count
 * @property CarbonImmutable|null $last_commented_at
 * @property CarbonImmutable|null $revisions_synced_at
 * @property CarbonImmutable|null $removed_at
 * @property string|null $removed_reason
 */
class Design extends Model
{
    use HasUuids;

    protected $table = 'marmara_designs';

    protected $fillable = [
        'workspace_id',
        'drive_file_id',
        'name',
        'title',
        'mime_type',
        'kind',
        'size',
        'md5',
        'folder_drive_id',
        'folder_path',
        'drive_created_at',
        'drive_modified_at',
        'owner_name',
        'owner_email',
        'modifier_name',
        'modifier_email',
        'uploader_name',
        'web_view_link',
        'width',
        'height',
        'head_revision_id',
        'status',
        'status_changed_at',
        'family_id',
        'family_latest',
        'votes_count',
        'comments_count',
        'last_commented_at',
        'revisions_synced_at',
        'removed_at',
        'removed_reason',
    ];

    protected $attributes = [
        'status' => 'draft',
        'folder_path' => '',
        'family_latest' => true,
        'votes_count' => 0,
        'comments_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'drive_created_at' => 'immutable_datetime',
            'drive_modified_at' => 'immutable_datetime',
            'status' => DesignStatus::class,
            'status_changed_at' => 'immutable_datetime',
            'family_latest' => 'boolean',
            'votes_count' => 'integer',
            'comments_count' => 'integer',
            'last_commented_at' => 'immutable_datetime',
            'revisions_synced_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
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
     * @return HasMany<DesignRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(DesignRevision::class)->orderBy('modified_at')->orderBy('id');
    }

    /**
     * @param  Builder<Design>  $query
     * @return Builder<Design>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    /**
     * The design and the files linked to it as versions, oldest first.
     *
     * @return Collection<int, Design>
     */
    public function family(): Collection
    {
        if ($this->family_id === null) {
            return new Collection([$this]);
        }

        return self::query()
            ->where('workspace_id', $this->workspace_id)
            ->where('family_id', $this->family_id)
            ->active()
            ->orderBy('drive_created_at')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * What the uploader shows: the TryPost member who uploaded it through
     * TryPost, else the file's Drive owner, else its last editor.
     */
    public function uploaderLabel(): ?string
    {
        return $this->uploader_name ?? $this->owner_name ?? $this->modifier_name;
    }

    public function isGoogleNative(): bool
    {
        return DesignTypes::isGoogleNative($this->mime_type);
    }

    /**
     * Changes whenever the file's content changes, so cached thumbnails of
     * an older content are never served for the new one.
     */
    public function contentVersion(): string
    {
        return substr(sha1(($this->md5 ?? '').'|'.($this->head_revision_id ?? '').'|'.($this->drive_modified_at?->toIso8601String() ?? '')), 0, 16);
    }
}
