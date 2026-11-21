<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Http;

use App\Http\Controllers\Controller;
use App\Marmara\Designs\Actions\CreatePostFromDesign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Gönderi oluştur": a draft with the design's original attached, then the
 * composer of the new post.
 */
class DesignPostController extends Controller
{
    use ResolvesDesigns;

    public function store(Request $request, string $design, CreatePostFromDesign $create): RedirectResponse
    {
        $model = $this->design($request, $design);
        $this->authorize('createPost', $model);
        abort_if($model->removed_at !== null, 404);

        $validated = $request->validate([
            'social_account_ids' => ['required', 'array', 'min:1', 'max:20'],
            'social_account_ids.*' => ['uuid'],
            'content' => ['nullable', 'string', 'max:20000'],
        ], ['social_account_ids.required' => __('marmara-designs::designs.post.needs_channel')]);

        $posts = $create->execute($model, $request->user(), array_values($validated['social_account_ids']), (string) ($validated['content'] ?? ''));

        return redirect()->route('app.posts.edit', $posts->first())
            ->with('flash.banner', __('marmara-designs::designs.post.created'));
    }
}
