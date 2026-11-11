<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Post\Approval\ApprovePost;
use App\Actions\Post\Approval\RejectPost;
use App\Http\Requests\Api\Post\ApprovePostRequest;
use App\Http\Requests\Api\Post\RejectPostRequest;
use App\Http\Resources\Api\PostResource;
use App\Models\Post;
use App\Models\PostApprovalAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

class PostApprovalController extends Controller
{
    public function approve(ApprovePostRequest $request, Post $post): PostResource|JsonResponse
    {
        $result = ApprovePost::execute(
            $post,
            $request->user(),
            Arr::only($request->validated(), ['publish_now', 'scheduled_at']),
            $request->validated('expected_version'),
            $request->validated('request_id'),
        );

        return $this->decided($request, data_get($result, 'post'), data_get($result, 'replayed'));
    }

    public function reject(RejectPostRequest $request, Post $post): PostResource|JsonResponse
    {
        $result = RejectPost::execute(
            $post,
            $request->user(),
            $request->validated('expected_version'),
            $request->validated('request_id'),
        );

        return $this->decided($request, data_get($result, 'post'), data_get($result, 'replayed'));
    }

    /**
     * The decided post. A request sent with a `request_id` also gets `replayed`:
     * true when that request had already been applied (nothing changed now),
     * with the `recorded_content_version` it was applied to.
     */
    private function decided(FormRequest $request, Post $post, ?PostApprovalAction $replayed): PostResource|JsonResponse
    {
        $post->load(['socialAccount', 'labels']);
        $resource = new PostResource($post);

        if ($request->validated('request_id') === null) {
            return $resource;
        }

        return response()->json([
            ...$resource->resolve($request),
            'replayed' => $replayed !== null,
            ...($replayed !== null ? ['recorded_content_version' => $replayed->content_version] : []),
        ]);
    }
}
