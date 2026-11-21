<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Http;

use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignComment;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Everything in the module belongs to the one designs workspace
 * (DesignsConfig::workspace(), MARMARA_DESIGNS_WORKSPACE_ID): whoever is not
 * a member of it gets 404, whatever their current workspace is or which
 * rights they have elsewhere. Callers then check the right they need
 * (createPost, update, …) on this workspace.
 */
trait ResolvesDesigns
{
    protected function workspace(Request $request): Workspace
    {
        $workspace = app(DesignsConfig::class)->workspace();
        $user = $request->user();

        abort_unless($workspace instanceof Workspace && $user !== null && $user->can('view', $workspace), 404);

        return $workspace;
    }

    /**
     * An API key belongs to one workspace: only a key of the designs
     * workspace reads it.
     */
    protected function tokenWorkspace(Request $request): Workspace
    {
        $workspace = $this->workspace($request);
        abort_unless($request->user()?->currentWorkspace?->id === $workspace->id, 404);

        return $workspace;
    }

    protected function design(Request $request, string $id): Design
    {
        abort_unless(Str::isUuid($id), 404);

        $design = Design::query()->where('workspace_id', $this->workspace($request)->id)->find($id);
        abort_if($design === null, 404);

        return $design;
    }

    protected function comment(Design $design, string $id): DesignComment
    {
        abort_unless(Str::isUuid($id), 404);

        $comment = DesignComment::query()->where('design_id', $design->id)->find($id);
        abort_if($comment === null, 404);

        return $comment;
    }
}
