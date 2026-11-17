<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Mcp;

use App\Marmara\Inbox\InboxConfig;
use App\Marmara\Inbox\InboxItemPayload;
use App\Marmara\Inbox\Models\InboxItem;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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
#[Description('Get one inbox item (comment or direct message) with its conversation (thread: the other items of the same comment thread or DM conversation), the replies already sent from TryPost (status sent, failed or sending) and whether it can still be answered (can_reply, reply_window_ends_at in UTC). Reading it does not mark it read.')]
class GetInboxItemTool extends Tool
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

        $validated = $request->validate(['item_id' => ['required', 'uuid']]);
        $item = InboxItem::query()->where('workspace_id', $workspace->id)->find($validated['item_id']);

        if ($item === null) {
            return Response::error('Inbox item not found.');
        }

        return Response::structured(InboxItemPayload::detail($item));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->string()->required()->description('UUID of the inbox item (id from list-inbox-items-tool or the inbox.item_received webhook).'),
        ];
    }
}
