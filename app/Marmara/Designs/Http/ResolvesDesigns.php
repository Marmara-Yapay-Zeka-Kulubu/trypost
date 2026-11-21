<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Http;

use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignComment;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Designs are looked up inside the user's current workspace only: an id of
 * another workspace answers 404, like the inbox.
 */
trait ResolvesDesigns
{
    protected function workspace(Request $request): Workspace
    {
        $workspace = $request->user()?->currentWorkspace;
        abort_unless($workspace instanceof Workspace, 404);
        $this->authorize('view', $workspace);

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
