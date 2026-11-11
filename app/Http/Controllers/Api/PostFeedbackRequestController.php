<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Post\Feedback\RequestPostFeedback;
use App\Http\Requests\Api\PostFeedback\StorePostFeedbackRequest;
use App\Http\Resources\Api\PostNoteResource;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PostFeedbackRequestController extends Controller
{
    public function store(StorePostFeedbackRequest $request, Post $post): JsonResponse
    {
        $note = RequestPostFeedback::execute(
            $post,
            $request->user(),
            $request->validated('user_ids'),
            $request->validated('note'),
        );

        return (new PostNoteResource($note))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
