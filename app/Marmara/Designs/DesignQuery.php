<?php

declare(strict_types=1);

namespace App\Marmara\Designs;

use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignTag;
use App\Marmara\Designs\Support\DesignStatus;
use App\Marmara\Designs\Support\DesignTypes;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
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
        $query = Design::query()->with(['tags', 'uploader'])->where('workspace_id', $workspace->id)->active();

        if (! filter_var($filters['all_versions'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->where('family_latest', true);
        }

        if (filled($filters['q'] ?? null)) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim((string) $filters['q']))).'%';
            // The title set in TryPost, the one from Drive and the file name.
            $query->where(fn (Builder $inner) => $inner->whereRaw('lower(custom_title) like ?', [$term])->orWhereRaw('lower(title) like ?', [$term])->orWhereRaw('lower(name) like ?', [$term]));
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

            if (Str::isUuid($uploader)) {
                // A member: every design credited to them, whatever its source.
                $query->where('uploader_user_id', $uploader);
            } else {
                // A name: the group of designs without a member, and links
                // made when the filter still went by the recorded name.
                $query->where(fn (Builder $inner) => $inner->where('uploader_name', $uploader)
                    ->orWhere(fn (Builder $owner) => $owner->whereNull('uploader_name')->where('owner_name', $uploader))
                    ->orWhereIn('uploader_user_id', User::query()->where('name', $uploader)->select('id')));
            }
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
            'title' => $query->orderByRaw('lower(coalesce(custom_title, title))')->orderBy('id'),
            'votes' => $query->orderByDesc('votes_count')->orderByDesc('drive_created_at')->orderBy('id'),
            'last_comment' => $query->orderByRaw('last_commented_at is null')->orderByDesc('last_commented_at')->orderByDesc('drive_created_at')->orderBy('id'),
            default => $query->orderByDesc('drive_created_at')->orderBy('id'),
        };
    }

    /**
     * The values the page offers in its filter selects.
     *
     * @return array{folders: list<string>, uploaders: list<array{value: string, label: string}>, tags: list<string>}
     */
    public static function options(Workspace $workspace): array
    {
        $active = Design::query()->where('workspace_id', $workspace->id)->active();

        return [
            'folders' => (clone $active)->where('folder_path', '!=', '')->distinct()->orderBy('folder_path')->pluck('folder_path')->values()->all(),
            'uploaders' => self::uploaderOptions($active),
            'tags' => DesignTag::query()->whereIn('design_id', (clone $active)->select('id'))->distinct()->orderBy('tag')->pluck('tag')->values()->all(),
        ];
    }

    /**
     * One option per person: the members credited with a design (value: the
     * user id), then, for designs without a member, the recorded name
     * (value: the name), by label.
     *
     * @param  Builder<Design>  $active
     * @return list<array{value: string, label: string}>
     */
    private static function uploaderOptions(Builder $active): array
    {
        $members = User::query()
            ->whereIn('id', (clone $active)->whereNotNull('uploader_user_id')->select('uploader_user_id'))
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['value' => (string) $user->id, 'label' => (string) $user->name]);

        $names = (clone $active)->whereNull('uploader_user_id')
            ->selectRaw('coalesce(uploader_name, owner_name) as uploader')->distinct()->pluck('uploader')
            ->filter()
            ->map(fn (string $name): array => ['value' => $name, 'label' => $name]);

        return $members->concat($names)
            ->sortBy(fn (array $option): string => mb_strtolower($option['label']).'|'.$option['value'])
            ->values()
            ->all();
    }
}
