<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Mcp;

use App\Marmara\Inbox\InboxConfig;
use App\Marmara\Inbox\InboxItemPayload;
use App\Marmara\Inbox\Models\InboxItem;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('List the workspace inbox: comments and direct messages people sent to the connected channels, newest first. status: unread (default), read, archived, open (unread and read) or all. Optional platform (e.g. instagram, facebook, linkedin-page, tiktok) and kind (comment|message). limit 1-100 (default 20); pass the last item\'s received_at as before for the next page. Each item has can_reply and reply_window_ends_at (UTC): a DM can only be answered until then.')]
class ListInboxItemsTool extends Tool
{
    use AuthorizesMcpTool;

    public function shouldRegister(InboxConfig $config): bool
    {
        return $config->enabled();
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'view');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['unread', 'read', 'archived', 'open', 'all'])],
            'platform' => ['sometimes', 'string', 'max:32'],
            'kind' => ['sometimes', 'string', Rule::in(InboxItem::KINDS)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'before' => ['sometimes', 'date'],
        ]);

        $limit = (int) data_get($validated, 'limit', 20);
        $items = InboxItem::query()
            ->where('workspace_id', $workspace->id)
            ->status((string) data_get($validated, 'status', 'unread'))
            ->when(data_get($validated, 'platform'), fn ($query, $platform) => $query->where('platform', $platform))
            ->when(data_get($validated, 'kind'), fn ($query, $kind) => $query->where('kind', $kind))
            ->when(data_get($validated, 'before'), fn ($query, $before) => $query->where('received_at', '<', CarbonImmutable::parse($before)))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        return Response::structured([
            'items' => $items->take($limit)->map(fn (InboxItem $item): array => [...InboxItemPayload::item($item), 'status' => $item->status()])->values()->all(),
            'has_more' => $items->count() > $limit,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['unread', 'read', 'archived', 'open', 'all'])->description('Which items: unread (default), read, archived, open (unread and read) or all.'),
            'platform' => $schema->string()->description('Only this network, as TryPost names it (instagram, instagram-facebook, facebook, linkedin-page, tiktok, x).'),
            'kind' => $schema->string()->enum(InboxItem::KINDS)->description('comment or message (a direct message).'),
            'limit' => $schema->integer()->description('How many items, 1 to 100 (default 20).'),
            'before' => $schema->string()->description('ISO 8601 time: only items received before it (pass the last received_at for the next page).'),
        ];
    }
}
