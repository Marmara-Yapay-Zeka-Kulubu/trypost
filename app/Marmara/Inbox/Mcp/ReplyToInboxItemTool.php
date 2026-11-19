<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Mcp;

use App\Marmara\Inbox\Actions\SendInboxReply;
use App\Marmara\Inbox\Exceptions\InboxReplyRefused;
use App\Marmara\Inbox\InboxConfig;
use App\Marmara\Inbox\InboxItemPayload;
use App\Marmara\Inbox\InboxServiceProvider;
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

#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(true)]
#[Description('Reply publicly to a comment, or privately to a direct message, as the channel. It is sent to the network immediately, without approval, so only members who publish directly can use it. request_id is required: sending the same request_id again sends nothing and answers the stored result (replayed: true). Refused when the DM reply window has closed (Meta: 24 hours after the person\'s last message; check reply_window_ends_at) or when the text is longer than the network allows (LinkedIn 1,250 characters, Instagram comments 2,200 and DMs 1,000 bytes, Messenger 2,000, Facebook comments 8,000, TikTok comments 150). Refused when another member already replied (already_replied: tell the user who replied and what, and ask before sending again with acknowledged_reply_id) or is replying right now (reply_in_progress: try again in a few seconds). Plain text only. Answers id and status (sent or failed, with error).')]
class ReplyToInboxItemTool extends Tool
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
            'item_id' => ['required', 'uuid'],
            'text' => ['required', 'string', 'max:20000'],
            'request_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9:._-]+\z/'],
            'acknowledged_reply_id' => ['nullable', 'uuid'],
        ]);

        $item = InboxItem::query()->where('workspace_id', $workspace->id)->find($validated['item_id']);

        if ($item === null) {
            return Response::error('Inbox item not found.');
        }

        if ($denied = $this->denyUnlessCan($request, InboxServiceProvider::REPLY_ABILITY, $item, 'Inbox item not found.')) {
            return $denied;
        }

        try {
            $result = SendInboxReply::execute($item, $request->user(), $validated['text'], $validated['request_id'], $validated['acknowledged_reply_id'] ?? null);
        } catch (InboxReplyRefused $refused) {
            return Response::error($this->refusalMessage($refused));
        }

        return Response::structured(array_filter([
            'id' => $result['reply']->id,
            'status' => $result['reply']->status,
            'error' => $result['reply']->error,
            'replayed' => $result['replayed'],
        ], fn (mixed $value): bool => $value !== null));
    }

    private function refusalMessage(InboxReplyRefused $refused): string
    {
        $existing = $refused->existingReply;

        if ($existing === null) {
            return "{$refused->getMessage()} ({$refused->reason})";
        }

        $reply = InboxItemPayload::reply($existing);
        $who = $reply['author']['name'] ?? 'Someone';

        return match ($refused->reason) {
            'reply_in_progress' => "{$who} is sending a reply to this item right now (reply_in_progress). Nothing was sent. Tell the user and try again in a few seconds.",
            default => "{$who} already replied to this item at {$reply['created_at']} (already_replied, reply id {$reply['id']}, status {$reply['status']}): \"{$reply['text']}\". Nothing was sent. Tell the user who replied and what, and ask whether to send anyway; only if they say yes, call again with acknowledged_reply_id {$reply['id']} and a new request_id.",
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->string()->required()->description('UUID of the inbox item to answer.'),
            'text' => $schema->string()->required()->description('The reply, plain text.'),
            'request_id' => $schema->string()->required()->description('Idempotency key (letters, digits and : . _ -, up to 100). The same request_id never sends twice.'),
            'acknowledged_reply_id' => $schema->string()->description('Only after the user saw the replies already sent and still wants to send: the id of the newest reply shown to them. Leave it out on a normal send.'),
        ];
    }
}
