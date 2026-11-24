<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Http;

use App\Http\Controllers\Controller;
use App\Marmara\Designs\DesignsConfig;
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
 * temporary signed URL for webhook / API readers), the original download and
 * a video's inline playback (byte ranges passed through to Drive), all
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
        $model = Design::query()->where('workspace_id', app(DesignsConfig::class)->workspace()?->id)->find($design);
        abort_if($model === null, 404);

        return $this->thumbnailResponse($model, $size, $request, $thumbnails, 'public');
    }

    public function original(Request $request, string $design, DriveClient $drive): StreamedResponse
    {
        $model = $this->design($request, $design);
        $revision = $this->revision($model, $request);
        $exportMime = $model->isGoogleNative() ? DesignTypes::DRAWING_EXPORT_MIME : null;
        $mime = $exportMime ?? $revision?->mime_type ?? $model->mime_type;
        $filename = $model->isGoogleNative() ? "{$model->displayTitle()}.png" : ($revision?->original_filename ?? $model->name);

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

    /**
     * A video for the drawer's <video>: inline, with its own Content-Type,
     * and the browser's Range passed to Drive, so seeking and Safari work
     * and no request holds more than one chunk in memory.
     */
    public function play(Request $request, string $design, DriveClient $drive): StreamedResponse|Response
    {
        $model = $this->design($request, $design);
        abort_unless($model->kind === DesignTypes::VIDEO && ! $model->isGoogleNative() && $model->removed_at === null, 404);

        $range = trim((string) $request->header('Range'));
        $range = preg_match('/^bytes=(\d+-\d*|-\d+)$/', $range) === 1 ? $range : null;

        try {
            $media = $drive->media($model->drive_file_id, $range);
        } catch (DriveException $exception) {
            if ($exception->status === 416 && $model->size !== null) {
                return response('', 416, ['Content-Range' => "bytes */{$model->size}", 'Cache-Control' => 'no-store']);
            }

            abort($exception->isNotFound() ? 404 : 502, __('marmara-designs::designs.post.download_failed'));
        }

        $headers = [
            'Content-Type' => DesignTypes::kindFor($model->mime_type) === DesignTypes::VIDEO ? $model->mime_type : 'video/mp4',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        foreach (['Content-Length', 'Content-Range'] as $name) {
            if ($media->hasHeader($name)) {
                $headers[$name] = $media->getHeaderLine($name);
            }
        }

        $body = $media->getBody();

        return response()->stream(function () use ($body): void {
            while (! $body->eof()) {
                echo $body->read(1024 * 1024);
                flush();
            }
        }, $media->getStatusCode() === 206 ? 206 : 200, $headers);
    }

    private function thumbnailResponse(Design $design, int $size, Request $request, DesignThumbnails $thumbnails, string $cacheScope): Response
    {
        abort_if($size < 32 || $size > 4000, 404);
        $thumbnail = $thumbnails->get($design, $size, $this->revision($design, $request));

        if ($thumbnail === null) {
            // No picture (yet): a fresh video has none until Drive makes it.
            // An empty, uncached 404, so the next request asks Drive again.
            return response('', 404, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

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
