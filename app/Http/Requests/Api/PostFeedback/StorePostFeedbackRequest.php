<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\PostFeedback;

use App\Actions\Post\Feedback\ListFeedbackRecipients;
use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StorePostFeedbackRequest extends FormRequest
{
    public const int MAX_RECIPIENTS = 20;

    /**
     * Same rules as notes: the post is visible to the member (404 otherwise) and the
     * member can write in its workspace. Checked before validation so a post
     * of another workspace answers 404, never 422.
     */
    public function authorize(): bool
    {
        $gate = Gate::forUser($this->user());

        $gate->authorize('view', $this->feedbackPost());
        $gate->authorize('createPost', $this->feedbackPost()->workspace);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
            'user_ids.*' => ['bail', 'string', 'distinct', Rule::in(ListFeedbackRecipients::execute($this->feedbackPost(), $this->user())->modelKeys())],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_ids.required' => __('posts.feedback.errors.choose_members'),
            'user_ids.min' => __('posts.feedback.errors.choose_members'),
            'user_ids.max' => __('posts.feedback.errors.too_many', ['max' => self::MAX_RECIPIENTS]),
            'user_ids.*.in' => __('posts.feedback.errors.not_member'),
        ];
    }

    public function feedbackPost(): Post
    {
        return $this->route('post');
    }
}
