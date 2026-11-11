<?php

declare(strict_types=1);

namespace App\Actions\Post\Feedback;

use App\Actions\PostNote\CreatePostNote;
use App\Enums\Webhook\EventType;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\User;
use App\Services\WebhookService;
use Illuminate\Support\Facades\DB;

final class RequestPostFeedback
{
    /**
     * Asks other members of the post's workspace for feedback on it. TryPost
     * does not message them: it leaves a note on the post, written by the
     * member asking, and sends the post.feedback_requested webhook so an
     * outside tool (a chat bot, say) can ask them and post their replies back
     * as notes. The post itself is not changed.
     *
     * @param  list<string>  $userIds
     */
    public static function execute(Post $post, User $requester, array $userIds, ?string $note): PostNote
    {
        $recipients = ListFeedbackRecipients::execute($post, $requester)
            ->whereIn('id', $userIds)
            ->values();
        $note = filled($note) ? trim($note) : null;

        return DB::transaction(function () use ($post, $requester, $recipients, $note): PostNote {
            $names = $recipients->pluck('name')->implode(', ');
            $locale = $requester->preferredLocale();

            $postNote = CreatePostNote::execute($post, $requester, [
                'body' => $note === null
                    ? __('notes.feedback_requested', ['names' => $names], $locale)
                    : __('notes.feedback_requested_with_note', ['names' => $names, 'note' => $note], $locale),
            ]);

            DB::afterCommit(function () use ($post, $requester, $recipients, $note): void {
                $workspace = $post->workspace;

                if ($workspace === null) {
                    return;
                }

                $webhooks = app(WebhookService::class);

                $webhooks->dispatch(
                    $workspace,
                    EventType::PostFeedbackRequested,
                    $webhooks->feedbackPayload($post, $requester, $recipients, $note),
                );
            });

            return $postNote;
        });
    }
}
