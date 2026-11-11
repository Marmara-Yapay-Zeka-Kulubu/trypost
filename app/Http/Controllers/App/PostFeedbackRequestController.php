<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Post\Feedback\ListFeedbackRecipients;
use App\Actions\Post\Feedback\RequestPostFeedback;
use App\Http\Requests\App\PostFeedback\StorePostFeedbackRequest;
use App\Http\Resources\App\PostNoteResource;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PostFeedbackRequestController extends Controller
{
    /**
     * The members who can be asked for feedback on the post, for the picker.
     */
    public function recipients(Request $request, Post $post): JsonResponse
    {
        $this->authorize('view', $post);
        $this->authorize('createPost', $post->workspace);

        return response()->json(
            ListFeedbackRecipients::execute($post, $request->user())
                ->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'photo_url' => $member->photo_url,
                ])
                ->values(),
        );
    }

    public function store(StorePostFeedbackRequest $request, Post $post): JsonResponse
    {
        $note = RequestPostFeedback::execute(
            $post,
            $request->user(),
            $request->validated('user_ids'),
            $request->validated('note'),
        );

        return PostNoteResource::make($note)->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
