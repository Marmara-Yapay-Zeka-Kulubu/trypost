<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Http;

use App\Http\Controllers\Controller;
use App\Marmara\Designs\DesignPayload;
use App\Marmara\Designs\DesignQuery;
use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\DesignsServiceProvider;
use App\Marmara\Designs\DesignsTranslations;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignDriveConnection;
use App\Marmara\Designs\Models\DesignSyncState;
use App\Marmara\Designs\Models\DesignVote;
use App\Marmara\Designs\Posts\DesignPublications;
use App\Marmara\Designs\Support\DesignStatus;
use App\Marmara\Designs\Support\DesignTypes;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Tasarımlar" page: the gallery with filters and sorts, and the
 * selected design (`?design=`) in a drawer.
 *
 * Prop names never reuse a shared prop (HandleInertiaRequests, the other
 * modules' Inertia::share): a page prop silently replaces the shared one for
 * the whole layout, which once emptied the sidebar's `channels`. So the
 * channels for "Gönderi oluştur" are `postChannels`. PAGE_PROPS lists every
 * prop; a test checks it against the shared ones.
 */
class DesignsPageController extends Controller
{
    use ResolvesDesigns;

    public const int PER_PAGE = 48;

    public const array PAGE_PROPS = [
        'copy',
        'filters',
        'filterOptions',
        'designs',
        'pagination',
        'selected',
        'permissions',
        'driveUpload',
        'syncInfo',
        'postChannels',
        'linkCandidates',
        'sharedBy',
        'urls',
    ];

    public function index(Request $request, DesignsConfig $config): Response
    {
        $workspace = $this->workspace($request);
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([...DesignQuery::rules(), 'page' => ['nullable', 'integer', 'min:1', 'max:50'], 'design' => ['nullable', 'uuid']]);
        $page = (int) ($validated['page'] ?? 1);
        $filters = collect($validated)->only(array_keys(DesignQuery::rules()))->filter(fn (mixed $value): bool => $value !== null && $value !== '')->all();

        [$visible, $hasMore, $sets] = $this->cards($workspace, $filters, $page * self::PER_PAGE);
        $publications = DesignPublications::latestFor($visible->pluck('id'));
        $voted = DesignVote::query()->where('user_id', $user->id)->whereIn('design_id', $visible->pluck('id'))->pluck('design_id')->flip();
        $versionCounts = Design::query()->where('workspace_id', $workspace->id)->active()->whereNotNull('family_id')
            ->whereIn('family_id', $visible->pluck('family_id')->filter()->unique())
            ->selectRaw('family_id, count(*) as aggregate')->groupBy('family_id')->pluck('aggregate', 'family_id');

        $selected = isset($validated['design'])
            ? Design::query()->where('workspace_id', $workspace->id)->find($validated['design'])
            : null;

        return Inertia::render('marmara/Designs', [
            'copy' => DesignsTranslations::forLocale(app()->getLocale()),
            'filters' => ['sort' => 'newest', ...$filters],
            'filterOptions' => [
                ...DesignQuery::options($workspace),
                'statuses' => DesignStatus::values(),
                'kinds' => DesignTypes::KINDS,
                'sorts' => DesignQuery::SORTS,
            ],
            'designs' => $visible->map(fn (Design $design): array => [
                ...DesignPayload::item($design),
                'voted' => $voted->has($design->id),
                'versions_count' => $design->family_id !== null ? (int) ($versionCounts[$design->family_id] ?? 1) : 1,
                // A series is one card: its first design, with every member's id in order.
                'set' => $design->design_set_id !== null && isset($sets[$design->design_set_id])
                    ? ['id' => $design->design_set_id, 'count' => count($sets[$design->design_set_id]), 'item_ids' => $sets[$design->design_set_id]]
                    : null,
                'last_publication' => $publications[$design->id] ?? null,
            ])->values()->all(),
            'pagination' => ['page' => $page, 'has_more' => $hasMore, 'per_page' => self::PER_PAGE],
            'selected' => $selected === null ? null : [
                ...DesignPayload::detail($selected, $user),
                'owner_email' => $selected->owner_email,
                'modifier_email' => $selected->modifier_email,
                'voted' => DesignVote::query()->where('design_id', $selected->id)->where('user_id', $user->id)->exists(),
                'can' => [
                    'statuses' => collect(DesignStatus::cases())->filter(fn (DesignStatus $status): bool => Gate::forUser($user)->allows(DesignsServiceProvider::STATUS_ABILITY, [$selected, $status]))->map->value->values()->all(),
                    'create_post' => $user->can('createPost', $selected) && $selected->removed_at === null,
                ],
            ],
            'permissions' => [
                'canApprove' => $user->can('approvePosts', $workspace),
                'canCreatePost' => $user->can('createPost', $workspace),
                'canManageDrive' => $user->can('update', $workspace),
            ],
            'driveUpload' => $this->driveUpload($workspace, $user, $config),
            'syncInfo' => $this->syncInfo($workspace, $config),
            // Also without a selected design: several designs can be picked for one post.
            'postChannels' => ! $user->can('createPost', $workspace) ? [] : SocialAccount::query()->where('workspace_id', $workspace->id)->orderBy('platform')->get()
                ->map(fn (SocialAccount $account): array => ['id' => $account->id, 'name' => $account->display_name ?? $account->username, 'platform' => $account->platform->value, 'connected' => $account->status?->value === 'connected'])
                ->values()->all(),
            'linkCandidates' => $selected === null ? [] : Design::query()->where('workspace_id', $workspace->id)->active()->whereKeyNot($selected->id)
                ->when($selected->family_id !== null, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('family_id')->orWhere('family_id', '!=', $selected->family_id)))
                ->orderByDesc('drive_created_at')->limit(200)->get(['id', 'title', 'folder_path', 'drive_created_at'])
                ->map(fn (Design $design): array => ['id' => $design->id, 'title' => $design->title, 'folder' => $design->folder_path])->values()->all(),
            // How many designs each person shared (posts that went out) in the date range.
            'sharedBy' => DesignPublications::countsByMember($workspace, $filters['from'] ?? null, $filters['to'] ?? null),
            'urls' => $this->urls(),
        ]);
    }

    /**
     * The gallery's cards for the first $limit of them: the matching
     * designs in order, a series once (as its first design, wherever one of
     * its members matched), and whether there are more.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: Collection<int, Design>, 1: bool, 2: array<string, list<string>>}
     */
    private function cards(Workspace $workspace, array $filters, int $limit): array
    {
        $cards = [];
        $seenSets = [];
        $hasMore = false;

        foreach (DesignQuery::for($workspace, $filters)->lazy(200) as $design) {
            if ($design->design_set_id !== null) {
                if (isset($seenSets[$design->design_set_id])) {
                    continue;
                }

                $seenSets[$design->design_set_id] = true;
            }

            if (count($cards) === $limit) {
                $hasMore = true;

                break;
            }

            $cards[] = $design;
        }

        $sets = [];
        $members = $seenSets === [] ? collect() : Design::query()->with(['tags', 'uploader'])
            ->where('workspace_id', $workspace->id)->active()
            ->whereIn('design_set_id', array_keys($seenSets))
            ->orderBy('set_position')->orderBy('created_at')->orderBy('id')
            ->get()->groupBy('design_set_id');

        foreach ($cards as $index => $design) {
            $group = $design->design_set_id !== null ? $members->get($design->design_set_id) : null;

            if ($group !== null && $group->isNotEmpty()) {
                $sets[$design->design_set_id] = $group->pluck('id')->values()->all();
                $cards[$index] = $group->first();
            }
        }

        return [new Collection($cards), $hasMore, $sets];
    }

    /**
     * What the page needs to decide the upload UI, without any setup detail:
     *
     * - `off`: uploads are not set up here (no OAuth client, no shared folder,
     *   or another workspace). Nobody sees "Yükle" or a notice.
     * - `needs_connection`: set up but the lead's Drive is not connected (or
     *   the grant was revoked). Admins see "Bağla".
     * - `needs_folder`: connected, the shared folder is not picked yet.
     *   Admins see "Klasörü seç".
     * - `ready`: members who may create posts see "Yükle".
     *
     * @return array<string, mixed>
     */
    private function driveUpload(Workspace $workspace, User $user, DesignsConfig $config): array
    {
        $connection = DesignDriveConnection::query()->where('workspace_id', $workspace->id)->first();
        $manager = $user->can('update', $workspace);

        $state = match (true) {
            ! $config->oauthConfigured() || $config->folderId() === null || $config->workspace()?->id !== $workspace->id => 'off',
            $connection?->refresh_token === null => 'needs_connection',
            $connection?->folder_granted_at === null => 'needs_folder',
            default => 'ready',
        };

        return [
            'state' => $state,
            'can_upload' => $state === 'ready' && $user->can('createPost', $workspace),
            'can_manage' => $state !== 'off' && $manager,
            'email' => $state !== 'off' && $manager ? $connection?->google_email : null,
            'picker_configured' => $config->picker()['api_key'] !== null,
            'max_mb' => intdiv($config->uploadMaxBytes(), 1048576),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function syncInfo(Workspace $workspace, DesignsConfig $config): array
    {
        $configured = $config->folderId() !== null && $config->serviceAccountKeyPath() !== null && $config->workspace()?->id === $workspace->id;
        $state = $configured ? DesignSyncState::query()->where('workspace_id', $workspace->id)->where('folder_id', $config->folderId())->first() : null;

        return [
            'configured' => $configured,
            'status' => $state->status ?? null,
            'detail' => $state !== null && $state->status !== DesignSyncState::STATUS_OK ? $state->status_detail : null,
            'last_synced_at' => $state?->last_synced_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function urls(): array
    {
        return [
            'index' => route('marmara.designs.index'),
            'upload' => route('marmara.designs.upload'),
            'status' => route('marmara.designs.status', ['design' => '__ID__']),
            'tags' => route('marmara.designs.tags', ['design' => '__ID__']),
            'comments' => route('marmara.designs.comments.store', ['design' => '__ID__']),
            'comment' => route('marmara.designs.comments.update', ['design' => '__ID__', 'comment' => '__COMMENT__']),
            'vote' => route('marmara.designs.vote', ['design' => '__ID__']),
            'versions' => route('marmara.designs.versions.link', ['design' => '__ID__']),
            'original' => route('marmara.designs.original', ['design' => '__ID__']),
            'play' => route('marmara.designs.play', ['design' => '__ID__']),
            'posts' => route('marmara.designs.posts.store', ['design' => '__ID__']),
            'posts_many' => route('marmara.designs.posts.many'),
            'drive_connect' => route('marmara.designs.drive.connect'),
            'drive_picker_token' => route('marmara.designs.drive.picker-token'),
            'drive_folder' => route('marmara.designs.drive.folder'),
            'drive_disconnect' => route('marmara.designs.drive.disconnect'),
        ];
    }
}
