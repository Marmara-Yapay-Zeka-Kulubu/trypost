<?php

declare(strict_types=1);

namespace App\Marmara\Designs;

use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignTag;
use App\Marmara\Designs\Support\DesignStatus;
use App\Marmara\Designs\Support\DesignTypes;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * The gallery's filters and sorts, shared by the page, the REST API and the
 * list-designs MCP tool.
 */
class DesignQuery
{
    public const array SORTS = ['newest', 'oldest', 'title', 'votes', 'last_comment'];

    /** The folder filter's value for the shared folder itself. */
    public const string ROOT_FOLDER = '/';

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'folder' => ['nullable', 'string', 'max:1000'],
            'uploader' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(DesignStatus::values())],
            'tag' => ['nullable', 'string', 'max:50'],
            'kind' => ['nullable', 'string', Rule::in(DesignTypes::KINDS)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', Rule::in(self::SORTS)],
            'all_versions' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Design>
     */
    public static function for(Workspace $workspace, array $filters): Builder
    {
        $query = Design::query()->with('tags')->where('workspace_id', $workspace->id)->active();

        if (! filter_var($filters['all_versions'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->where('family_latest', true);
        }

        if (filled($filters['q'] ?? null)) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim((string) $filters['q']))).'%';
            $query->where(fn (Builder $inner) => $inner->whereRaw('lower(title) like ?', [$term])->orWhereRaw('lower(name) like ?', [$term]));
        }

        if (filled($filters['folder'] ?? null)) {
            $folder = trim((string) $filters['folder'], ' /');

            if ($folder === '') {
                $query->where('folder_path', '');
            } else {
                $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $folder).'/%';
                $query->where(fn (Builder $inner) => $inner->where('folder_path', $folder)->orWhere('folder_path', 'like', $prefix));
            }
        }

        if (filled($filters['uploader'] ?? null)) {
            $uploader = (string) $filters['uploader'];
            $query->where(fn (Builder $inner) => $inner->where('uploader_name', $uploader)
                ->orWhere(fn (Builder $owner) => $owner->whereNull('uploader_name')->where('owner_name', $uploader)));
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['tag'] ?? null)) {
            $query->whereIn('id', DesignTag::query()->where('tag', mb_strtolower(trim((string) $filters['tag'])))->select('design_id'));
        }

        if (filled($filters['kind'] ?? null)) {
            $query->where('kind', $filters['kind']);
        }

        if (filled($filters['from'] ?? null)) {
            $query->where('drive_created_at', '>=', CarbonImmutable::parse((string) $filters['from'])->startOfDay());
        }

        if (filled($filters['to'] ?? null)) {
            $query->where('drive_created_at', '<=', CarbonImmutable::parse((string) $filters['to'])->endOfDay());
        }

        return match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('drive_created_at')->orderBy('id'),
            'title' => $query->orderByRaw('lower(title)')->orderBy('id'),
            'votes' => $query->orderByDesc('votes_count')->orderByDesc('drive_created_at')->orderBy('id'),
            'last_comment' => $query->orderByRaw('last_commented_at is null')->orderByDesc('last_commented_at')->orderByDesc('drive_created_at')->orderBy('id'),
            default => $query->orderByDesc('drive_created_at')->orderBy('id'),
        };
    }

    /**
     * The values the page offers in its filter selects.
     *
     * @return array{folders: list<string>, uploaders: list<string>, tags: list<string>}
     */
    public static function options(Workspace $workspace): array
    {
        $active = Design::query()->where('workspace_id', $workspace->id)->active();

        return [
            'folders' => (clone $active)->where('folder_path', '!=', '')->distinct()->orderBy('folder_path')->pluck('folder_path')->values()->all(),
            'uploaders' => (clone $active)->selectRaw('coalesce(uploader_name, owner_name) as uploader')->distinct()->pluck('uploader')->filter()->sort()->values()->all(),
            'tags' => DesignTag::query()->whereIn('design_id', (clone $active)->select('id'))->distinct()->orderBy('tag')->pluck('tag')->values()->all(),
        ];
    }
}
