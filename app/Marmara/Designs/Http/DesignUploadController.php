<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Http;

use App\Http\Controllers\Controller;
use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Upload\DesignUploader;
use App\Marmara\Designs\Upload\DesignUploadUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The gallery's "Yükle": DesignUploader with the member's name as uploader.
 */
class DesignUploadController extends Controller
{
    use ResolvesDesigns;

    public function store(Request $request, DesignUploader $uploader): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('createPost', $workspace);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.intdiv(app(DesignsConfig::class)->uploadMaxBytes(), 1024)],
            'folder' => ['nullable', 'string', 'max:500'],
            'title' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $design = $uploader->upload($validated['file'], (string) ($validated['folder'] ?? ''), (string) ($validated['title'] ?? ''), (string) $request->user()->name, $request->user());
        } catch (DesignUploadUnavailable $unavailable) {
            throw ValidationException::withMessages(['file' => $unavailable->getMessage()]);
        }

        return redirect()->route('marmara.designs.index', ['design' => $design->id])->with('flash.banner', __('marmara-designs::designs.upload.done'));
    }
}
