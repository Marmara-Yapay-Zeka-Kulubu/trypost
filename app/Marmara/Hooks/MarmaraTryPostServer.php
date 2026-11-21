<?php

declare(strict_types=1);

namespace App\Marmara\Hooks;

use App\Mcp\Servers\TryPostServer;

/**
 * Upstream's MCP server plus the tools the fork's modules register
 * (MarmaraMcpTools). Bound in place of TryPostServer by a module provider
 * (laravel/mcp builds the server through the container, in the web transport
 * and in TryPostServer::actingAs() alike); the inbox's server extends it.
 * Each tool's shouldRegister() hides it while its module is off.
 */
class MarmaraTryPostServer extends TryPostServer
{
    protected function boot(): void
    {
        parent::boot();

        $this->tools = array_values(array_unique([...$this->tools, ...MarmaraMcpTools::all()]));
    }
}
