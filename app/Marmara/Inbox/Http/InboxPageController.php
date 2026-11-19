<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Http;

use App\Http\Controllers\Controller;
use App\Marmara\Inbox\Actions\SendInboxReply;
use App\Marmara\Inbox\Exceptions\InboxProviderException;
use App\Marmara\Inbox\Exceptions\InboxReplyRefused;
use App\Marmara\Inbox\InboxItemPayload;
use App\Marmara\Inbox\InboxProviders;
use App\Marmara\Inbox\InboxServiceProvider;
use App\Marmara\Inbox\InboxTranslations;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Models\InboxReply;
use App\Marmara\Inbox\Models\InboxSyncState;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Gelen Kutusu" page: list with filters, thread, reply box, read and
 * archive. Every member of the workspace can read it; replying and hiding
 * are for members who publish directly (a reply goes out without approval).
 */
class InboxPageController extends Controller
{
    public function index(Request $request, InboxProviders $providers): Response
    {
        $workspace = $this->workspace($request);
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['unread', 'open', 'archived', 'all'])],
            'platform' => ['nullable', 'string', 'max:32'],
            'kind' => ['nullable', 'string', Rule::in(InboxItem::KINDS)],
            'item' => ['nullable', 'uuid'],
        ]);
        $status = (string) ($filters['status'] ?? 'open');

        $items = InboxItem::query()
            ->with('socialAccount')
            ->where('workspace_id', $workspace->id)
            ->status($status)
            ->when($filters['platform'] ?? null, fn ($query, $platform) => $query->where('platform', $platform))
            ->when($filters['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->orderByDesc('received_at')
            ->limit(100)
            ->get();

        $selected = isset($filters['item'])
            ? InboxItem::query()->where('workspace_id', $workspace->id)->find($filters['item'])
            : null;

        $selected?->markRead();

        return Inertia::render('marmara/Inbox', [
            'copy' => InboxTranslations::forLocale(app()->getLocale()),
            'filters' => ['status' => $status, 'platform' => $filters['platform'] ?? null, 'kind' => $filters['kind'] ?? null],
            'platforms' => InboxItem::query()->where('workspace_id', $workspace->id)->distinct()->orderBy('platform')->pluck('platform')->all(),
            'items' => $items->map(fn (InboxItem $item): array => $this->row($item))->all(),
            'selected' => $selected === null ? null : [
                ...InboxItemPayload::detail($selected),
                ...$this->row($selected),
                'can_hide' => $this->canHide($selected, $providers),
            ],
            'canReply' => $request->user()->can('publishDirectly', $workspace),
            'channels' => $this->channelStatuses($workspace, $providers),
            'urls' => [
                'index' => route('marmara.inbox.index'),
                'item' => route('marmara.inbox.index', ['item' => '__ID__']),
                'replies' => route('marmara.inbox.replies.store', ['item' => '__ID__']),
                'read' => route('marmara.inbox.read', ['item' => '__ID__']),
                'unread' => route('marmara.inbox.unread', ['item' => '__ID__']),
                'archive' => route('marmara.inbox.archive', ['item' => '__ID__']),
                'unarchive' => route('marmara.inbox.unarchive', ['item' => '__ID__']),
                'hide' => route('marmara.inbox.hide', ['item' => '__ID__']),
            ],
        ]);
    }

    public function reply(Request $request, string $item): RedirectResponse
    {
        $inboxItem = $this->item($request, $item);
        $this->authorize(InboxServiceProvider::REPLY_ABILITY, $inboxItem);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:20000'],
            'request_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9:._-]+\z/'],
            'acknowledged_reply_id' => ['nullable', 'uuid'],
        ]);

        try {
            $result = SendInboxReply::execute($inboxItem, $request->user(), $validated['text'], $validated['request_id'] ?? null, $validated['acknowledged_reply_id'] ?? null);
        } catch (InboxReplyRefused $refused) {
            // `already_replied` also names the reply, so the page can ask
            // "send anyway?" and resend with it as acknowledged_reply_id.
            throw ValidationException::withMessages(array_filter([
                'text' => $this->refusalMessage($refused),
                'already_replied' => $refused->reason === 'already_replied' ? $refused->existingReply?->id : null,
            ]));
        }

        if ($result['reply']->status === InboxReply::STATUS_FAILED) {
            throw ValidationException::withMessages(['text' => __('marmara-inbox::inbox.errors.send_failed')]);
        }

        return back();
    }

    public function read(Request $request, string $item): RedirectResponse
    {
        $this->item($request, $item)->markRead();

        return back();
    }

    public function unread(Request $request, string $item): RedirectResponse
    {
        $this->item($request, $item)->forceFill(['read_at' => null])->save();

        return back();
    }

    public function archive(Request $request, string $item): RedirectResponse
    {
        $inboxItem = $this->item($request, $item);
        $inboxItem->forceFill(['archived_at' => now(), 'read_at' => $inboxItem->read_at ?? now()])->save();

        return back();
    }

    public function unarchive(Request $request, string $item): RedirectResponse
    {
        $this->item($request, $item)->forceFill(['archived_at' => null])->save();

        return back();
    }

    public function hide(Request $request, string $item, InboxProviders $providers): RedirectResponse
    {
        $inboxItem = $this->item($request, $item);
        $this->authorize(InboxServiceProvider::REPLY_ABILITY, $inboxItem);
        $account = $inboxItem->socialAccount;
        $provider = $account ? $providers->for($account) : null;

        if (! $this->canHide($inboxItem, $providers) || $provider === null) {
            throw ValidationException::withMessages(['hide' => __('marmara-inbox::inbox.errors.hide_unavailable')]);
        }

        try {
            $provider->hide($inboxItem);
        } catch (InboxProviderException) {
            throw ValidationException::withMessages(['hide' => __('marmara-inbox::inbox.errors.hide_failed')]);
        }

        $inboxItem->forceFill(['hidden_at' => now()])->save();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(InboxItem $item): array
    {
        return [
            ...InboxItemPayload::item($item),
            'status' => $item->status(),
            'hidden' => $item->hidden_at !== null,
            'channel_name' => $item->socialAccount?->display_name ?? $item->socialAccount?->username,
        ];
    }

    private function canHide(InboxItem $item, InboxProviders $providers): bool
    {
        $account = $item->socialAccount;
        $provider = $account ? $providers->for($account) : null;

        return $provider !== null
            && $provider->key() === $item->provider
            && $item->hidden_at === null
            && (bool) data_get($provider->capabilities($account), "{$item->kind}.hide", false)
            && $provider->missingReplyPermission($account, $item->kind) === null;
    }

    /**
     * The sync status of each channel the inbox reads, so the page can say
     * "needs permission X" instead of staying silently empty.
     *
     * @return list<array<string, mixed>>
     */
    private function channelStatuses(Workspace $workspace, InboxProviders $providers): array
    {
        $states = InboxSyncState::query()
            ->whereIn('social_account_id', SocialAccount::query()->where('workspace_id', $workspace->id)->select('id'))
            ->get()
            ->groupBy('social_account_id');

        return SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->get()
            ->map(function (SocialAccount $account) use ($providers, $states): ?array {
                $provider = $providers->for($account);

                if ($provider === null) {
                    return null;
                }

                return [
                    'id' => $account->id,
                    'name' => $account->display_name ?? $account->username,
                    'platform' => $account->platform->value,
                    'provider' => $provider->key(),
                    'kinds' => collect($provider->capabilities($account))->map(function (array $can, string $kind) use ($account, $provider, $states): array {
                        $state = $states->get($account->id)?->first(fn (InboxSyncState $state): bool => $state->provider === $provider->key() && $state->kind === $kind);

                        return [
                            'kind' => $kind,
                            'status' => $state->status ?? InboxSyncState::STATUS_PENDING,
                            'detail' => $state?->status_detail,
                            'reply_needs' => $provider->missingReplyPermission($account, $kind),
                            'last_synced_at' => $state?->last_synced_at?->toIso8601String(),
                        ];
                    })->values()->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function refusalMessage(InboxReplyRefused $refused): string
    {
        return match ($refused->reason) {
            'reply_window_closed' => __('marmara-inbox::inbox.errors.window_closed'),
            'text_too_long' => __('marmara-inbox::inbox.errors.too_long', ['detail' => $refused->getMessage()]),
            'already_replied' => __('marmara-inbox::inbox.errors.already_replied', [
                'name' => $refused->existingReply?->user?->name ?? __('marmara-inbox::inbox.list.unknown_author'),
                'text' => Str::limit((string) $refused->existingReply?->text, 120),
            ]),
            'reply_in_progress' => __('marmara-inbox::inbox.errors.reply_in_progress', [
                'name' => $refused->existingReply?->user?->name ?? __('marmara-inbox::inbox.list.unknown_author'),
            ]),
            default => __('marmara-inbox::inbox.errors.cannot_reply', ['detail' => $refused->getMessage()]),
        };
    }

    private function workspace(Request $request): Workspace
    {
        $workspace = $request->user()?->currentWorkspace;
        abort_unless($workspace instanceof Workspace, 404);
        $this->authorize('view', $workspace);

        return $workspace;
    }

    private function item(Request $request, string $id): InboxItem
    {
        abort_unless(Str::isUuid($id), 404);

        $item = InboxItem::query()->where('workspace_id', $this->workspace($request)->id)->find($id);
        abort_if($item === null, 404);

        return $item;
    }
}
