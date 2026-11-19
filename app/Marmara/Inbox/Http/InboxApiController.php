<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Http;

use App\Http\Controllers\Controller;
use App\Marmara\Inbox\Actions\SendInboxReply;
use App\Marmara\Inbox\Exceptions\InboxReplyRefused;
use App\Marmara\Inbox\InboxItemPayload;
use App\Marmara\Inbox\InboxServiceProvider;
use App\Marmara\Inbox\Models\InboxItem;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The inbox REST API for Echo / Hermes (API key auth and workspace scoping
 * like the approvals endpoints: `auth:api`, `workspace.token`). Items of
 * another workspace answer 404.
 */
class InboxApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['unread', 'read', 'archived', 'open', 'all'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'platform' => ['nullable', 'string', 'max:32'],
            'kind' => ['nullable', 'string', Rule::in(InboxItem::KINDS)],
            'before' => ['nullable', 'date'],
        ]);

        $workspace = $this->workspace($request);
        $limit = (int) ($validated['limit'] ?? 20);

        $items = InboxItem::query()
            ->where('workspace_id', $workspace->id)
            ->status((string) ($validated['status'] ?? 'open'))
            ->when($validated['platform'] ?? null, fn ($query, $platform) => $query->where('platform', $platform))
            ->when($validated['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->when($validated['before'] ?? null, fn ($query, $before) => $query->where('received_at', '<', CarbonImmutable::parse($before)))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        return response()->json([
            'data' => $items->take($limit)->map(fn (InboxItem $item): array => [
                ...InboxItemPayload::item($item),
                'status' => $item->status(),
            ])->values()->all(),
            'has_more' => $items->count() > $limit,
        ]);
    }

    public function show(Request $request, string $item): JsonResponse
    {
        return response()->json(InboxItemPayload::detail($this->item($request, $item)));
    }

    public function reply(Request $request, string $item): JsonResponse
    {
        $inboxItem = $this->item($request, $item);

        if (! Gate::forUser($request->user())->allows(InboxServiceProvider::REPLY_ABILITY, $inboxItem)) {
            return response()->json(['message' => 'Only members who publish directly can reply.'], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:20000'],
            'request_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9:._-]+\z/'],
            'acknowledged_reply_id' => ['nullable', 'uuid'],
        ]);

        try {
            $result = SendInboxReply::execute($inboxItem, $request->user(), $validated['text'], $validated['request_id'], $validated['acknowledged_reply_id'] ?? null);
        } catch (InboxReplyRefused $refused) {
            return response()->json(array_filter([
                'message' => $refused->getMessage(),
                'code' => $refused->reason,
                'reply' => $refused->existingReply !== null ? InboxItemPayload::reply($refused->existingReply) : null,
            ], fn (mixed $value): bool => $value !== null), $refused->status);
        }

        $reply = $result['reply'];

        return response()->json(array_filter([
            'id' => $reply->id,
            'status' => $reply->status,
            'error' => $reply->error,
        ], fn (mixed $value): bool => $value !== null), Response::HTTP_CREATED);
    }

    public function read(Request $request, string $item): JsonResponse
    {
        $inboxItem = $this->item($request, $item);
        $inboxItem->markRead();

        return response()->json(['id' => $inboxItem->id, 'status' => $inboxItem->status()]);
    }

    private function workspace(Request $request): Workspace
    {
        $workspace = $request->user()?->currentWorkspace;
        abort_unless($workspace instanceof Workspace, 401);

        return $workspace;
    }

    private function item(Request $request, string $id): InboxItem
    {
        abort_unless(Str::isUuid($id), 404, 'Inbox item not found.');

        $item = InboxItem::query()
            ->where('workspace_id', $this->workspace($request)->id)
            ->whereKey($id)
            ->first();

        abort_if($item === null, 404, 'Inbox item not found.');

        return $item;
    }
}
