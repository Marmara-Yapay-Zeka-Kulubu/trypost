<?php

declare(strict_types=1);

namespace App\Actions\Post\Feedback;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class ListFeedbackRecipients
{
    /**
     * The members of the post's workspace who can be asked for feedback on it:
     * everyone but the member asking, by name.
     *
     * @return Collection<int, User>
     */
    public static function execute(Post $post, User $requester): Collection
    {
        $workspace = $post->workspace;

        if ($workspace === null) {
            return new Collection;
        }

        return $workspace->members()
            ->with('avatarMedia')
            ->where('users.id', '!=', $requester->id)
            ->orderBy('users.name')
            ->get();
    }
}
