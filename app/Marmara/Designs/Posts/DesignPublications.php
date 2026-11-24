<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Posts;

use App\Enums\Post\PublishStatus;
use App\Marmara\Designs\Models\DesignPost;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where designs went out, for the card ("Paylaşıldı · Instagram · Behice ·
 * 24 Kas", the latest), the drawer (every one) and the per-person counts.
 * Only recorded publications count (`published_at` set by
 * RecordDesignPublication) of posts that went out.
 */
class DesignPublications
{
    /**
     * The latest publication of each design.
     *
     * @param  iterable<string>  $designIds
     * @return array<string, array<string, mixed>>
     */
    public static function latestFor(iterable $designIds): array
    {
        $ids = collect($designIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return self::query()
            ->whereIn('design_id', $ids)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get()
            ->unique('design_id')
            ->mapWithKeys(fn (DesignPost $link): array => [$link->design_id => self::item($link)])
            ->all();
    }

    /**
     * Every publication of the design, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forDesign(string $designId): array
    {
        return self::query()
            ->where('design_id', $designId)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (DesignPost $link): array => self::item($link))
            ->values()
            ->all();
    }

    /**
     * How many designs each person shared (posts that went out, by the
     * post's member), most first; within the range when one is given.
     *
     * @return list<array{user_id: string, name: string, count: int}>
     */
    public static function countsByMember(Workspace $workspace, ?string $from = null, ?string $to = null): array
    {
        $rows = DesignPost::query()
            ->join('posts', 'posts.id', '=', 'marmara_design_posts.post_id')
            ->where('marmara_design_posts.workspace_id', $workspace->id)
            ->whereNotNull('marmara_design_posts.published_at')
            ->whereNotNull('posts.user_id')
            ->whereIn('posts.publish_status', self::wentOutValues())
            ->when(filled($from), fn (Builder $query) => $query->where('marmara_design_posts.published_at', '>=', CarbonImmutable::parse((string) $from)->startOfDay()))
            ->when(filled($to), fn (Builder $query) => $query->where('marmara_design_posts.published_at', '<=', CarbonImmutable::parse((string) $to)->endOfDay()))
            ->groupBy('posts.user_id')
            ->selectRaw('posts.user_id as user_id, count(distinct marmara_design_posts.design_id) as aggregate')
            ->toBase()
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = User::query()->whereIn('id', $rows->pluck('user_id'))->pluck('name', 'id');

        return $rows
            ->map(fn (object $row): array => ['user_id' => (string) $row->user_id, 'name' => (string) ($names[$row->user_id] ?? ''), 'count' => (int) $row->aggregate])
            ->filter(fn (array $row): bool => $row['name'] !== '')
            ->sortBy([['count', 'desc'], [fn (array $a, array $b): int => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']))]])
            ->values()
            ->all();
    }

    /**
     * @return Builder<DesignPost>
     */
    private static function query(): Builder
    {
        return DesignPost::query()
            ->with(['post.user'])
            ->whereNotNull('published_at')
            ->whereHas('post', fn (Builder $post) => $post->whereIn('publish_status', self::wentOutValues()));
    }

    /**
     * @return list<string>
     */
    private static function wentOutValues(): array
    {
        return array_map(fn (PublishStatus $status): string => $status->value, PublishStatus::wentOutCases());
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(DesignPost $link): array
    {
        $post = $link->post;

        return [
            'post_id' => $link->post_id,
            'platform' => $post?->platform?->value,
            'platform_label' => $post?->platform?->label(),
            'account' => $post?->platform_name ?? $post?->platform_username,
            'by' => $post?->user === null ? null : ['id' => $post->user->id, 'name' => $post->user->name],
            'url' => $post?->platform_url,
            'published_at' => $link->published_at?->toIso8601String(),
            'deleted' => $post?->publish_status === PublishStatus::Deleted,
        ];
    }
}
