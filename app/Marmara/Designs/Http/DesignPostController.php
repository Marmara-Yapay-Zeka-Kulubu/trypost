<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Http;

use App\Http\Controllers\Controller;
use App\Marmara\Designs\Actions\CreatePostFromDesign;
use App\Marmara\Designs\Models\Design;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Gönderi oluştur": a draft with the design's original attached (or several
 * designs' as a carousel), then the composer of the new post.
 */
class DesignPostController extends Controller
{
    use ResolvesDesigns;

    /** A carousel takes at most 10 items on Instagram, 20 on some networks; CreatePosts checks each channel. */
    public const int MAX_DESIGNS = 20;

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

    /**
     * Several designs (picked on the page, or a series) as one draft per
     * channel with all of them as media, in the order given.
     */
    public function storeMany(Request $request, CreatePostFromDesign $create): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $validated = $request->validate([
            'design_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_DESIGNS],
            'design_ids.*' => ['uuid', 'distinct'],
            'social_account_ids' => ['required', 'array', 'min:1', 'max:20'],
            'social_account_ids.*' => ['uuid'],
            'content' => ['nullable', 'string', 'max:20000'],
        ], [
            'social_account_ids.required' => __('marmara-designs::designs.post.needs_channel'),
            'design_ids.max' => __('marmara-designs::designs.post.too_many', ['max' => self::MAX_DESIGNS]),
        ]);

        $ids = array_values($validated['design_ids']);
        $found = Design::query()->with('workspace')->where('workspace_id', $workspace->id)->whereIn('id', $ids)->whereNull('removed_at')->get()->keyBy('id');
        abort_if($found->count() !== count($ids), 404);

        $designs = new EloquentCollection(array_map(fn (string $id): Design => $found->get($id), $ids));
        $designs->each(fn (Design $design) => $this->authorize('createPost', $design));

        $posts = $create->executeMany($designs, $request->user(), array_values($validated['social_account_ids']), (string) ($validated['content'] ?? ''));

        return redirect()->route('app.posts.edit', $posts->first())
            ->with('flash.banner', __('marmara-designs::designs.post.created'));
    }
}
