<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Mcp;

use App\Marmara\Designs\Actions\DesignActions;
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

#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('Add a comment to a design, as the connected TryPost user. The comment stays in TryPost (it is not written to Google Drive); the team sees it on the design and the design.commented webhook fires. Plain text, up to 5,000 characters. Answers the stored comment.')]
class CommentOnDesignTool extends Tool
{
    use AuthorizesMcpTool;

    public function shouldRegister(DesignsConfig $config): bool
    {
        return $config->enabled();
    }

    public function handle(Request $request, DesignActions $actions): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'view');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        if (! app(DesignsConfig::class)->isDesignsWorkspace($workspace)) {
            return Response::error('Designs are not available in this workspace.');
        }

        $validated = $request->validate([
            'design_id' => ['required', 'uuid'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $design = Design::query()->where('workspace_id', $workspace->id)->find($validated['design_id']);

        if ($design === null) {
            return Response::error('Design not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'comment', $design, 'Design not found.')) {
            return $denied;
        }

        $comment = $actions->comment($design, $request->user(), $validated['body']);

        return Response::structured(DesignPayload::comment($comment->load('user'), $request->user()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'design_id' => $schema->string()->required()->description('UUID of the design.'),
            'body' => $schema->string()->required()->description('The comment, plain text.'),
        ];
    }
}
