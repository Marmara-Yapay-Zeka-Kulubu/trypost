<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Http;

use App\Http\Controllers\Controller;
use App\Marmara\Designs\Actions\DesignActions;
use App\Marmara\Designs\DesignsServiceProvider;
use App\Marmara\Designs\Support\DesignStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The gallery's changes: status, tags, comments, votes and version links.
 * Each answers back() for the Inertia page.
 */
class DesignController extends Controller
{
    use ResolvesDesigns;

    public function __construct(private readonly DesignActions $actions) {}

    public function status(Request $request, string $design): RedirectResponse
    {
        $model = $this->design($request, $design);
        $validated = $request->validate(['status' => ['required', 'string', Rule::in(DesignStatus::values())]]);
        $status = DesignStatus::from($validated['status']);

        // The same gate as the API and MCP (DesignPolicy::setStatus).
        Gate::authorize(DesignsServiceProvider::STATUS_ABILITY, [$model, $status]);

        $this->actions->setStatus($model, $request->user(), $status);

        return back();
    }

    public function tags(Request $request, string $design): RedirectResponse
    {
        $model = $this->design($request, $design);
        $this->authorize('tag', $model);
        $validated = $request->validate([
            'tags' => ['present', 'array', 'max:'.DesignActions::MAX_TAGS],
            'tags.*' => ['string', 'max:50'],
        ]);

        $this->actions->syncTags($model, $request->user(), $validated['tags']);

        return back();
    }

    public function storeComment(Request $request, string $design): RedirectResponse
    {
        $model = $this->design($request, $design);
        $this->authorize('comment', $model);
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $this->actions->comment($model, $request->user(), $validated['body']);

        return back();
    }

    public function updateComment(Request $request, string $design, string $comment): RedirectResponse
    {
        $model = $this->comment($this->design($request, $design), $comment);
        $this->authorize(DesignsServiceProvider::COMMENT_UPDATE_ABILITY, $model);
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $this->actions->editComment($model, $request->user(), $validated['body']);

        return back();
    }

    public function destroyComment(Request $request, string $design, string $comment): RedirectResponse
    {
        $model = $this->comment($this->design($request, $design), $comment);
        $this->authorize(DesignsServiceProvider::COMMENT_DELETE_ABILITY, $model);

        $this->actions->deleteComment($model, $request->user());

        return back();
    }

    public function vote(Request $request, string $design): RedirectResponse
    {
        $model = $this->design($request, $design);
        $this->authorize('vote', $model);

        $this->actions->vote($model, $request->user());

        return back();
    }

    public function unvote(Request $request, string $design): RedirectResponse
    {
        $model = $this->design($request, $design);
        $this->authorize('vote', $model);

        $this->actions->unvote($model, $request->user());

        return back();
    }

    public function linkVersion(Request $request, string $design): RedirectResponse
    {
        $model = $this->design($request, $design);
        $this->authorize('linkVersion', $model);
        $validated = $request->validate(['previous_id' => ['required', 'uuid']]);

        $this->actions->linkVersion($model, $request->user(), $this->design($request, $validated['previous_id']));

        return back();
    }

    public function unlinkVersion(Request $request, string $design): RedirectResponse
    {
        $model = $this->design($request, $design);
        $this->authorize('linkVersion', $model);

        $this->actions->unlinkVersion($model, $request->user());

        return back();
    }
}
