<?php

declare(strict_types=1);

namespace App\Exceptions\Post;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An approve, reject or pending edit of a post that is no longer waiting for
 * approval. It stays a validation error on `post` (422); JSON answers also
 * carry `"error": "not_pending"` so an outside tool can tell it apart.
 */
class PostNotPendingException extends ValidationException
{
    public static function make(): self
    {
        return self::withMessages(['post' => __('posts.approvals.errors.not_pending')]);
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'errors' => $this->errors(),
            'error' => 'not_pending',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
