<?php

declare(strict_types=1);

namespace App\Marmara\Members\Actions;

use App\Enums\Post\Origin;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Marmara\Members\Support\WorkspaceMembers;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Üye bazlı paylaşımlar": per member (posts.user_id, so a post Hermes made
 * for a WhatsApp sender counts for the sender), the posts written in TryPost
 * within a period:
 *
 * - published: the network published it (publish_status published, or
 *   deleted there since), published_at in the period; also split by platform;
 * - failed: status failed, due (scheduled_at, else created_at) in the period;
 * - scheduled: status scheduled or publishing, scheduled_at in the period;
 * - pending_approval: waiting for approval, due (scheduled_at, else created_at) in the period;
 * - drafts: status draft, created_at in the period;
 * - via_whatsapp: of all those, the ones the agent created for the member.
 *
 * Every member of the workspace gets a row (zeros included); a former member
 * with posts in the period gets one too. Imported posts (origin network) and
 * posts without an author are left out.
 */
class BuildMemberPostStats
{
    /**
     * @param  list<string>|null  $channelIds  null for every channel
     * @return list<array{user: array{id: string, name: string, photo_url: string|null}, published: int, failed: int, scheduled: int, pending_approval: int, drafts: int, via_whatsapp: int, platforms: array<string, int>}>
     */
    public function handle(Workspace $workspace, CarbonInterface $start, CarbonInterface $end, ?array $channelIds = null): array
    {
        $range = [$start->copy()->utc(), $end->copy()->utc()];
        $wentOut = array_map(fn (PublishStatus $status): string => $status->value, PublishStatus::wentOutCases());
        $due = 'COALESCE(posts.scheduled_at, posts.created_at)';

        $published = fn (Builder $query): Builder => $query
            ->whereIn('posts.publish_status', $wentOut)
            ->whereNotNull('posts.published_at')
            ->whereBetween('posts.published_at', $range);

        $buckets = [
            'published' => $published,
            'failed' => fn (Builder $query): Builder => $query->where('posts.status', PostStatus::Failed)->whereBetween(DB::raw($due), $range),
            'scheduled' => fn (Builder $query): Builder => $query->whereIn('posts.status', [PostStatus::Scheduled, PostStatus::Publishing])->whereBetween('posts.scheduled_at', $range),
            'pending_approval' => fn (Builder $query): Builder => $query->where('posts.status', PostStatus::PendingApproval)->whereBetween(DB::raw($due), $range),
            'drafts' => fn (Builder $query): Builder => $query->where('posts.status', PostStatus::Draft)->whereBetween('posts.created_at', $range),
        ];

        $base = fn (): Builder => Post::query()
            ->where('posts.workspace_id', $workspace->id)
            ->where('posts.origin', Origin::TryPost)
            ->whereNotNull('posts.user_id')
            ->onChannels($channelIds);

        /** @var array<string, array<string, int>> $counts */
        $counts = [];

        foreach ($buckets as $bucket => $scope) {
            $rows = $scope($base())
                ->toBase()
                ->select('posts.user_id')
                ->selectRaw('count(*) as aggregate')
                ->selectRaw('sum(case when posts.agent_user_id is not null then 1 else 0 end) as agent_aggregate')
                ->groupBy('posts.user_id')
                ->get();

            foreach ($rows as $row) {
                $counts[(string) $row->user_id][$bucket] = (int) $row->aggregate;
                $counts[(string) $row->user_id]['via_whatsapp'] = ($counts[(string) $row->user_id]['via_whatsapp'] ?? 0) + (int) $row->agent_aggregate;
            }
        }

        /** @var array<string, array<string, int>> $platforms */
        $platforms = [];

        $published($base())
            ->toBase()
            ->select(['posts.user_id', 'posts.platform'])
            ->selectRaw('count(*) as aggregate')
            ->groupBy(['posts.user_id', 'posts.platform'])
            ->get()
            ->each(function (object $row) use (&$platforms): void {
                if (filled($row->platform)) {
                    $platforms[(string) $row->user_id][(string) $row->platform] = (int) $row->aggregate;
                }
            });

        $members = WorkspaceMembers::query($workspace)->with('avatarMedia')->get();
        $formerIds = array_diff(array_keys($counts), $members->modelKeys());
        $users = $members->concat($formerIds === [] ? [] : User::query()->with('avatarMedia')->whereKey($formerIds)->get());

        return $users
            ->map(fn (User $user): array => [
                'user' => ['id' => (string) $user->id, 'name' => (string) $user->name, 'photo_url' => $user->photo_url],
                'published' => $counts[$user->id]['published'] ?? 0,
                'failed' => $counts[$user->id]['failed'] ?? 0,
                'scheduled' => $counts[$user->id]['scheduled'] ?? 0,
                'pending_approval' => $counts[$user->id]['pending_approval'] ?? 0,
                'drafts' => $counts[$user->id]['drafts'] ?? 0,
                'via_whatsapp' => $counts[$user->id]['via_whatsapp'] ?? 0,
                'platforms' => collect($platforms[$user->id] ?? [])->sortDesc()->all(),
            ])
            ->sortBy([
                fn (array $left, array $right): int => $right['published'] <=> $left['published'],
                fn (array $left, array $right): int => ($right['scheduled'] + $right['drafts']) <=> ($left['scheduled'] + $left['drafts']),
                fn (array $left, array $right): int => strcasecmp($left['user']['name'], $right['user']['name']),
            ])
            ->values()
            ->all();
    }
}
