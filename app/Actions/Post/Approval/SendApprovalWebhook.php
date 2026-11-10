<?php

declare(strict_types=1);

namespace App\Actions\Post\Approval;

use App\Enums\Webhook\EventType;
use App\Models\Post;
use App\Models\User;
use App\Services\WebhookService;
use Illuminate\Support\Facades\DB;

final class SendApprovalWebhook
{
    /**
     * Sends an approval webhook (requested, approved or rejected) for one post
     * once the change commits, so an outside tool (a chat bot, say) can follow
     * the approval flow.
     */
    public static function execute(Post $post, EventType $event, ?User $requester, ?User $approver = null): void
    {
        DB::afterCommit(function () use ($post, $event, $requester, $approver): void {
            $workspace = $post->workspace;

            if ($workspace === null) {
                return;
            }

            $webhooks = app(WebhookService::class);

            $webhooks->dispatch($workspace, $event, $webhooks->approvalPayload($post, $requester, $approver));
        });
    }
}
