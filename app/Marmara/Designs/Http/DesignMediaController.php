<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Http;

use App\Http\Controllers\Controller;
use App\Marmara\Designs\Drive\DriveClient;
use App\Marmara\Designs\Drive\DriveException;
use App\Marmara\Designs\Models\Design;
use App\Marmara\Designs\Models\DesignRevision;
use App\Marmara\Designs\Support\DesignTypes;
use App\Marmara\Designs\Thumbnails\DesignThumbnails;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The authenticated thumbnail proxy (members of the design's workspace, or a
 * temporary signed URL for webhook / API readers) and the original download,
 * streamed from Drive and never stored.
 */
class DesignMediaController extends Controller
{
    use ResolvesDesigns;

    public function thumbnail(Request $request, string $design, int $size, DesignThumbnails $thumbnails): Response
    {
        return $this->thumbnailResponse($this->design($request, $design), $size, $request, $thumbnails, 'private');
    }

    public function signedThumbnail(Request $request, string $design, int $size, DesignThumbnails $thumbnails): Response
    {
        abort_unless(Str::isUuid($design), 404);
        $model = Design::query()->find($design);
        abort_if($model === null, 404);

        return $this->thumbnailResponse($model, $size, $request, $thumbnails, 'public');
    }

    public function original(Request $request, string $design, DriveClient $drive): StreamedResponse
    {
        $model = $this->design($request, $design);
        $revision = $this->revision($model, $request);
        $exportMime = $model->isGoogleNative() ? DesignTypes::DRAWING_EXPORT_MIME : null;
        $mime = $exportMime ?? $revision?->mime_type ?? $model->mime_type;
        $filename = $model->isGoogleNative() ? "{$model->title}.png" : ($revision?->original_filename ?? $model->name);

        try {
            $stream = $drive->stream($model->drive_file_id, $revision?->revision_id, $exportMime);
        } catch (DriveException $exception) {
            abort($exception->isNotFound() ? 404 : 502, __('marmara-designs::designs.post.download_failed'));
        }

        return response()->streamDownload(function () use ($stream): void {
            while (! $stream->eof()) {
                echo $stream->read(1024 * 1024);
                flush();
            }
        }, $filename, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store'], $request->boolean('inline') ? 'inline' : 'attachment');
    }

    private function thumbnailResponse(Design $design, int $size, Request $request, DesignThumbnails $thumbnails, string $cacheScope): Response
    {
        abort_if($size < 32 || $size > 4000, 404);
        $thumbnail = $thumbnails->get($design, $size, $this->revision($design, $request));
        abort_if($thumbnail === null, 404);

        return response($thumbnail['bytes'], 200, [
            'Content-Type' => $thumbnail['mime_type'],
            'Content-Length' => (string) strlen($thumbnail['bytes']),
            // The URL carries the content version, so the browser may keep it.
            'Cache-Control' => "{$cacheScope}, max-age=86400",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function revision(Design $design, Request $request): ?DesignRevision
    {
        $id = $request->query('revision');

        if (! is_string($id) || $id === '') {
            return null;
        }

        $revision = DesignRevision::query()->where('design_id', $design->id)->where('revision_id', $id)->first();
        abort_if($revision === null, 404);

        return $revision;
    }
}
