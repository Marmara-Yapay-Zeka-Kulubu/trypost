<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Http;

use App\Http\Controllers\Controller;
use App\Marmara\Designs\Actions\DesignActions;
use App\Marmara\Designs\DesignPayload;
use App\Marmara\Designs\DesignQuery;
use App\Marmara\Designs\DesignsServiceProvider;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Support\DesignStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * REST for Echo / Hermes (API key, workspace of the key): list, read,
 * comment and change the status. Thumbnail URLs are temporary signed URLs,
 * since API readers have no TryPost session.
 */
class DesignsApiController extends Controller
{
    use ResolvesDesigns;

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $validated = $request->validate([...DesignQuery::rules(), 'limit' => ['nullable', 'integer', 'min:1', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $limit = (int) ($validated['limit'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);

        $designs = DesignQuery::for($workspace, $validated)->forPage($page, $limit + 1)->get();

        return response()->json([
            'data' => $designs->take($limit)->map(fn (Design $design): array => DesignPayload::item($design, signedThumbnails: true))->values()->all(),
            'has_more' => $designs->count() > $limit,
        ]);
    }

    public function show(Request $request, string $design): JsonResponse
    {
        return response()->json(DesignPayload::detail($this->design($request, $design), $request->user(), signedThumbnails: true));
    }

    public function comment(Request $request, string $design, DesignActions $actions): JsonResponse
    {
        $model = $this->design($request, $design);
        $this->authorize('comment', $model);
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $comment = $actions->comment($model, $request->user(), $validated['body']);

        return response()->json(DesignPayload::comment($comment->load('user'), $request->user()), 201);
    }

    public function status(Request $request, string $design, DesignActions $actions): JsonResponse
    {
        $model = $this->design($request, $design);
        $validated = $request->validate(['status' => ['required', 'string', Rule::in(DesignStatus::values())]]);
        $status = DesignStatus::from($validated['status']);

        if (Gate::denies(DesignsServiceProvider::STATUS_ABILITY, [$model, $status])) {
            return response()->json(['message' => __('marmara-designs::designs.errors.status_forbidden'), 'code' => 'status_forbidden'], 403);
        }

        $actions->setStatus($model, $request->user(), $status);

        return response()->json(DesignPayload::item($model->fresh(), signedThumbnails: true));
    }
}
