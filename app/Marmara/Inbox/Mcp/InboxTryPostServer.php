<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Mcp;

use App\Marmara\Hooks\MarmaraTryPostServer;

/**
 * Upstream's MCP server plus the inbox tools (and, through
 * MarmaraTryPostServer, the other modules' tools). Bound in place of
 * TryPostServer by InboxServiceProvider (laravel/mcp builds the server
 * through the container, in the web transport and in tests alike); each
 * tool's shouldRegister() hides it while MARMARA_INBOX_ENABLED is off.
 */
class InboxTryPostServer extends MarmaraTryPostServer
{
    /** @var list<class-string> */
    public const array INBOX_TOOLS = [
        ListInboxItemsTool::class,
        GetInboxItemTool::class,
        ReplyToInboxItemTool::class,
    ];

    protected function boot(): void
    {
        parent::boot();

        $this->tools = [...$this->tools, ...self::INBOX_TOOLS];
    }
}
