<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\Approval\RejectPost;
use App\Exceptions\Post\ApprovalRequestConflictException;
use App\Exceptions\Post\PostVersionMismatchException;
use App\Exceptions\Post\QueueBusyException;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Post;
use App\Models\PostApprovalAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
#[Description('Reject a post that is waiting for approval (status pending_approval). The post goes back to drafts for its author; no reason is stored. Only members who publish directly can reject.')]
class RejectPostTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'post_id' => ['required', 'uuid'],
            'expected_version' => ['nullable', 'string', 'max:64'],
            'request_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9:._-]+\z/'],
        ]);

        $workspace = $request->user()?->currentWorkspace;
        $post = $workspace
            ? Post::where('workspace_id', $workspace->id)->find(data_get($validated, 'post_id'))
            : null;

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'approve', $post, 'Post not found.')) {
            return $denied;
        }

        try {
            $result = RejectPost::execute($post, $request->user(), data_get($validated, 'expected_version'), data_get($validated, 'request_id'));
        } catch (QueueBusyException) {
            return Response::error(__('posts.errors.queue_busy'));
        } catch (PostVersionMismatchException $exception) {
            return Response::error("{$exception->getMessage()} (content_version: {$exception->contentVersion})");
        } catch (ApprovalRequestConflictException $exception) {
            return Response::error($exception->getMessage());
        }

        /** @var Post $rejected */
        $rejected = data_get($result, 'post');
        $rejected->load(['socialAccount', 'labels']);

        return Response::structured(self::decided((new PostResource($rejected))->resolve(), $validated, data_get($result, 'replayed')));
    }

    /**
     * Adds `replayed` (and the recorded version) when the call sent a `request_id`.
     *
     * @param  array<string, mixed>  $post
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private static function decided(array $post, array $validated, ?PostApprovalAction $replayed): array
    {
        if (data_get($validated, 'request_id') === null) {
            return $post;
        }

        return [
            ...$post,
            'replayed' => $replayed !== null,
            ...($replayed !== null ? ['recorded_content_version' => $replayed->content_version] : []),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('UUID of the post waiting for approval.'),
            'request_id' => $schema->string()->description('Optional idempotency key (letters, digits and : . _ -, up to 100). Sending the same request_id again changes nothing and answers with replayed: true, even once the post is no longer pending.'),
            'expected_version' => $schema->string()->description('Optional content_version of the post as it was reviewed. When the post changed since, nothing is rejected and the error gives the current content_version.'),
        ];
    }
}
