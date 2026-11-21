<?php

declare(strict_types=1);

namespace App\Marmara\Designs;

use App\Enums\Webhook\EventType;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignComment;
use App\Marmara\Designs\Support\DesignStatus;
use App\Models\User;
use App\Services\WebhookService;
use Illuminate\Support\Facades\DB;

/**
 * The design.* webhooks, sent through TryPost's own webhooks (signed with
 * X-Webhook-Signature like every event) to the workspace's endpoints that
 * subscribe to them, after the change committed. The payload's `design` is
 * DesignPayload::item with signed thumbnail URLs (no session needed), so
 * Echo can show the picture on WhatsApp.
 */
class DesignEvents
{
    public function __construct(
        private readonly DesignsConfig $config,
        private readonly WebhookService $webhooks,
    ) {}

    public function added(Design $design): void
    {
        $this->send($design, EventType::DesignAdded, []);
    }

    public function commented(Design $design, DesignComment $comment, User $user): void
    {
        $this->send($design, EventType::DesignCommented, [
            'comment' => [
                'id' => $comment->id,
                'body' => $comment->body,
                'author' => ['id' => $user->id, 'name' => $user->name],
                'created_at' => $comment->created_at->toIso8601String(),
            ],
        ]);
    }

    public function statusChanged(Design $design, User $user, DesignStatus $from): void
    {
        $this->send($design, EventType::DesignStatusChanged, [
            'status' => [
                'from' => $from->value,
                'from_label' => $from->label(),
                'to' => $design->status->value,
                'to_label' => $design->status->label(),
                'changed_by' => ['id' => $user->id, 'name' => $user->name],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function send(Design $design, EventType $event, array $extra): void
    {
        if (! $this->config->enabled()) {
            return;
        }

        DB::afterCommit(function () use ($design, $event, $extra): void {
            $workspace = $design->workspace;

            if ($workspace !== null) {
                $this->webhooks->dispatch($workspace, $event, ['design' => DesignPayload::item($design->fresh() ?? $design, signedThumbnails: true), ...$extra]);
            }
        });
    }
}
