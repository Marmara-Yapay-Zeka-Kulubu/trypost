<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Post\Approval\ApprovePost;
use App\Actions\Post\Approval\RejectPost;
use App\Http\Requests\Api\Post\ApprovePostRequest;
use App\Http\Requests\Api\Post\RejectPostRequest;
use App\Http\Resources\Api\PostResource;
use App\Models\Post;
use Illuminate\Support\Arr;

class PostApprovalController extends Controller
{
    public function approve(ApprovePostRequest $request, Post $post): PostResource
    {
        /** @var Post $approved */
        $approved = data_get(ApprovePost::execute(
            $post,
            $request->user(),
            Arr::only($request->validated(), ['publish_now', 'scheduled_at']),
            $request->validated('expected_version'),
        ), 'post');
        $approved->load(['socialAccount', 'labels']);

        return new PostResource($approved);
    }

    public function reject(RejectPostRequest $request, Post $post): PostResource
    {
        $rejected = RejectPost::execute($post, $request->user(), $request->validated('expected_version'));
        $rejected->load(['socialAccount', 'labels']);

        return new PostResource($rejected);
    }
}
