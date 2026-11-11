<?php

declare(strict_types=1);

namespace App\Exceptions\Post;

use App\Models\PostApprovalAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An approve or reject sent with a `request_id` that already made the other
 * decision on the post (see PostApprovalAction). Nothing was changed.
 */
class ApprovalRequestConflictException extends RuntimeException
{
    public function __construct(public readonly PostApprovalAction $recorded)
    {
        parent::__construct(__('posts.approvals.errors.request_conflict'));
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'error' => 'request_id_conflict',
            ], Response::HTTP_CONFLICT);
        }

        return back()->withErrors(['post' => $this->getMessage()]);
    }
}
