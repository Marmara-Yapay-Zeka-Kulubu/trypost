<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Mcp;

use App\Marmara\Designs\DesignPayload;
use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Models\Design;
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
#[Description('Get one design of the gallery with its comments (comments_list: author, body, time), its versions oldest first (versions: v1, v2, … with their own thumbnail_url; is_current marks this file\'s current content), tags, votes, status and the recent activity. drive_url opens it in Google Drive.')]
class GetDesignTool extends Tool
{
    use AuthorizesMcpTool;

    public function shouldRegister(DesignsConfig $config): bool
    {
        return $config->enabled();
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'view');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(['design_id' => ['required', 'uuid']]);
        $design = Design::query()->where('workspace_id', $workspace->id)->find($validated['design_id']);

        if ($design === null) {
            return Response::error('Design not found.');
        }

        return Response::structured(DesignPayload::detail($design, $request->user(), signedThumbnails: true));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'design_id' => $schema->string()->required()->description('UUID of the design (id from list-designs-tool or a design.* webhook).'),
        ];
    }
}
