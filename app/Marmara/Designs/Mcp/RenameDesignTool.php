<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Mcp;

use App\Marmara\Designs\Actions\DesignActions;
use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Support\DesignMembers;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Hermes / Echo's "şu tasarımın adını değiştir": the page's "Başlığı
 * düzenle" (DesignActions::rename). Only the title shown in TryPost
 * changes; the Drive file keeps its name.
 */
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('Rename a design of the gallery: sets the title TryPost shows for it (on the Tasarımlar page, in the API, the MCP tools, webhooks and the file name of posts made from it). The file in Google Drive keeps its name. title: the new title (up to 255 characters, trimmed); an empty title removes the custom title and the design shows its Drive file name again. current_title: the title the design shows now, as list-designs-tool or get-design-tool gave it; when given and the design shows another title, nothing changes and the call fails (a check that design_id is the design you mean). on_behalf_of_user_id is the TryPost user id of the person who asked (a member of this workspace; the change is credited to them in the design\'s history). Answers id, title (the title shown now), drive_name (the Drive file name, unchanged) and url (opens the design on the Tasarımlar page).')]
class RenameDesignTool extends Tool
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
            'title' => ['present', 'nullable', 'string', 'max:'.DesignActions::MAX_TITLE],
            'current_title' => ['sometimes', 'nullable', 'string', 'max:'.DesignActions::MAX_TITLE],
            'on_behalf_of_user_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        // The person who asked, as a member: only a member of this workspace.
        $member = null;

        if (filled($validated['on_behalf_of_user_id'] ?? null)) {
            $member = DesignMembers::find($workspace, (string) $validated['on_behalf_of_user_id']);

            if ($member === null) {
                throw ValidationException::withMessages(['on_behalf_of_user_id' => 'on_behalf_of_user_id is not a member of this workspace. Leave it out to rename as the connected TryPost user.']);
            }
        }

        $design = Design::query()->where('workspace_id', $workspace->id)->find($validated['design_id']);

        if ($design === null) {
            return Response::error('Design not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'rename', $design, 'Design not found.')) {
            return $denied;
        }

        $expected = Str::squish((string) ($validated['current_title'] ?? ''));

        if ($expected !== '' && $expected !== Str::squish($design->displayTitle())) {
            return Response::error("The design shows the title \"{$design->displayTitle()}\", not \"{$expected}\". Nothing was changed; check design_id with get-design-tool.");
        }

        $actions->rename($design, $member ?? $request->user(), $validated['title'] ?? null);

        return Response::structured([
            'id' => $design->id,
            'title' => $design->displayTitle(),
            'drive_name' => $design->name,
            'url' => route('marmara.designs.index', ['design' => $design->id]),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'design_id' => $schema->string()->required()->description('UUID of the design (id from list-designs-tool, get-design-tool or a design.* webhook).'),
            'title' => $schema->string()->required()->description('The new title, up to 255 characters. Empty string: back to the Drive file name.'),
            'current_title' => $schema->string()->description('The title the design shows now (title from list-designs-tool or get-design-tool). When it does not match, the call fails and nothing changes.'),
            'on_behalf_of_user_id' => $schema->string()->description('TryPost user id (UUID) of the person who asked for the rename, when known (Echo\'s "[TryPost kullanıcı: <uuid>]" note). Must be a member of this workspace, otherwise the call fails. The change is credited to that member. Never guess it.'),
        ];
    }
}
