<?php

declare(strict_types=1);

namespace App\Exceptions\Post;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An approve or reject sent with `expected_version` for a post whose
 * content changed since that version (see Post::contentVersion()). Nothing
 * was changed; the response carries the current version.
 */
class PostVersionMismatchException extends RuntimeException
{
    public function __construct(public readonly string $contentVersion)
    {
        parent::__construct(__('posts.approvals.errors.changed'));
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'content_version' => $this->contentVersion,
            ], Response::HTTP_CONFLICT);
        }

        return back()->withErrors(['post' => $this->getMessage()]);
    }
}
