<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Post\Approval\ApprovePost;
use App\Actions\Post\Approval\RejectPost;
use App\Http\Requests\App\Post\ApprovePostRequest;
use App\Http\Requests\App\Post\RejectPostRequest;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;

class PostApprovalController extends Controller
{
    public function approve(ApprovePostRequest $request, Post $post): RedirectResponse
    {
        ApprovePost::execute(
            $post,
            $request->user(),
            Arr::only($request->validated(), ['publish_now', 'scheduled_at']),
            $request->validated('expected_version'),
        );

        return back();
    }

    public function reject(RejectPostRequest $request, Post $post): RedirectResponse
    {
        RejectPost::execute($post, $request->user(), $request->validated('expected_version'));

        return back();
    }
}
