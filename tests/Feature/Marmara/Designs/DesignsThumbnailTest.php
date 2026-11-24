<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use App\Marmara\Designs\DesignPayload;
use App\Marmara\Designs\Models\DesignRevision;
use App\Marmara\Designs\Models\DesignThumbnail;
use App\Marmara\Designs\Thumbnails\ThumbnailCache;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    ['workspace' => $this->workspace, 'user' => $this->owner] = createApiTestToken();
    designsConfigure($this->workspace);
    File::deleteDirectory(config('marmara-designs.thumbnails.path'));
    $this->design = designsMake($this->workspace, ['drive_file_id' => 'filePoster']);
    designsFakeDrive(thumbnails: [
        'https://lh3.googleusercontent.com/drive-storage/filePoster=s400' => designsPng(400, 300),
        'https://lh3.googleusercontent.com/drive-storage/filePoster=s1600' => designsPng(1600, 1200),
    ], content: ['filePoster@rev-old' => designsPng(2000, 1500)]);
});

afterEach(fn () => File::deleteDirectory(config('marmara-designs.thumbnails.path')));

test('members get the drive thumbnail through the proxy; it is fetched with the service account token and then cached', function () {
    $response = $this->actingAs($this->owner)->get("/designs/{$this->design->id}/thumbnail/400")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and(getimagesizefromstring($response->getContent())[0])->toBe(400);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://lh3.googleusercontent.com/') && $request->hasHeader('Authorization', 'Bearer sa-access-token'));
    $sent = count(Http::recorded());

    $this->actingAs($this->owner)->get("/designs/{$this->design->id}/thumbnail/400")->assertOk();
    expect(Http::recorded())->toHaveCount($sent)
        ->and(DesignThumbnail::query()->count())->toBe(1);
});

test('the proxy needs a session and a member of the design\'s workspace', function () {
    $this->get("/designs/{$this->design->id}/thumbnail/400")->assertRedirect();
    $this->actingAs(workspaceOutsider($this->workspace))->get("/designs/{$this->design->id}/thumbnail/400")->assertNotFound();

    ['user' => $stranger] = createApiTestToken();
    $this->actingAs($stranger)->get("/designs/{$this->design->id}/thumbnail/400")->assertNotFound();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'googleusercontent'));
});

test('the signed url of a webhook payload works without a session; tampered or unsigned urls do not', function () {
    $signed = DesignPayload::thumbnailUrl($this->design, 400, signed: true);

    $this->get($signed)->assertOk();
    $this->get(str_replace('/400?', '/1600?', $signed))->assertForbidden();
    $this->get("/marmara/designs/{$this->design->id}/thumbnail/400")->assertForbidden();
});

test('an older revision is downloaded and scaled down here, then cached', function () {
    DesignRevision::query()->create(['design_id' => $this->design->id, 'revision_id' => 'rev-old', 'mime_type' => 'image/png', 'size' => 50000, 'md5' => 'old']);

    $response = $this->actingAs($this->owner)->get("/designs/{$this->design->id}/thumbnail/1600?revision=rev-old")->assertOk();

    expect(getimagesizefromstring($response->getContent())[0])->toBe(1600);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/files/filePoster/revisions/rev-old') && str_contains($request->url(), 'alt=media'));

    $this->actingAs($this->owner)->get("/designs/{$this->design->id}/thumbnail/400?revision=missing")->assertNotFound();
});

test('a new content version is a new cache entry, so an edit never shows the old picture', function () {
    $this->actingAs($this->owner)->get("/designs/{$this->design->id}/thumbnail/400")->assertOk();
    $this->design->forceFill(['md5' => 'edited', 'head_revision_id' => 'rev-2'])->save();
    $this->actingAs($this->owner)->get("/designs/{$this->design->id}/thumbnail/400")->assertOk();

    expect(DesignThumbnail::query()->count())->toBe(2);
});

test('the cache evicts the least recently used thumbnails once it is over its limit', function () {
    config()->set('marmara-designs.thumbnails.max_mb', 1);
    $cache = app(ThumbnailCache::class);
    $chunk = str_repeat('x', 300 * 1024);

    $cache->put('a', null, $chunk, 'image/jpeg');
    $this->travel(2)->minutes();
    $cache->put('b', null, $chunk, 'image/jpeg');
    $this->travel(2)->minutes();
    expect($cache->get('a'))->not->toBeNull(); // a is now more recent than b
    $this->travel(2)->minutes();
    $cache->put('c', null, $chunk, 'image/jpeg');
    $this->travel(2)->minutes();
    $cache->put('d', null, $chunk, 'image/jpeg');

    expect(DesignThumbnail::query()->orderBy('cache_key')->pluck('cache_key')->all())->toBe(['a', 'c', 'd'])
        ->and($cache->totalBytes())->toBeLessThanOrEqual(1024 * 1024)
        ->and($cache->get('b'))->toBeNull()
        ->and(count(File::allFiles(config('marmara-designs.thumbnails.path'))))->toBe(3);
});

test('originals are streamed from drive on demand and never stored', function () {
    designsFakeDrive(content: ['filePoster' => 'ORIGINAL-BYTES']);

    $response = $this->actingAs($this->owner)->get("/designs/{$this->design->id}/original")->assertOk();

    expect($response->streamedContent())->toBe('ORIGINAL-BYTES')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and(DesignThumbnail::query()->count())->toBe(0);
});

test('a fresh video without a drive thumbnail gets an empty uncached 404, and its thumbnail shows once drive makes it', function () {
    $video = designsMake($this->workspace, ['drive_file_id' => 'fileClip', 'name' => 'Klip.mp4', 'title' => 'Klip', 'mime_type' => 'video/mp4', 'kind' => 'video', 'size' => 11 * 1024 * 1024]);
    designsFakeDrive(thumbnails: ['fileClip' => null]);

    $response = $this->actingAs($this->owner)->get("/designs/{$video->id}/thumbnail/400")->assertNotFound();

    expect($response->getContent())->toBe('')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(DesignThumbnail::query()->count())->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/files/fileClip') && str_contains($request->url(), 'alt=media'));

    $this->get(DesignPayload::thumbnailUrl($video, 400, signed: true))->assertNotFound();

    // Some minutes later Drive has made it: the same URL now answers with it.
    designsFakeDrive(thumbnails: ['https://lh3.googleusercontent.com/drive-storage/fileClip=s400' => designsJpeg(400, 225)]);

    $response = $this->actingAs($this->owner)->get("/designs/{$video->id}/thumbnail/400")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and(DesignThumbnail::query()->count())->toBe(1);
});

test('an answer from drive that is not a picture is never cached as a thumbnail', function () {
    $video = designsMake($this->workspace, ['drive_file_id' => 'fileClip', 'mime_type' => 'video/mp4', 'kind' => 'video']);
    designsFakeDrive(thumbnails: ['https://lh3.googleusercontent.com/drive-storage/fileClip=s400' => '<!doctype html><title>Processing</title>']);

    $this->actingAs($this->owner)->get("/designs/{$video->id}/thumbnail/400")->assertNotFound();

    expect(DesignThumbnail::query()->count())->toBe(0);
});

test('a video plays inline from drive, with byte ranges passed through', function () {
    $bytes = str_repeat('0123456789', 100);
    $video = designsMake($this->workspace, ['drive_file_id' => 'fileClip', 'mime_type' => 'video/mp4', 'kind' => 'video', 'size' => strlen($bytes)]);
    designsFakeDrive(content: ['fileClip' => $bytes]);

    $full = $this->actingAs($this->owner)->get("/designs/{$video->id}/play")->assertOk();

    expect($full->streamedContent())->toBe($bytes)
        ->and($full->headers->get('Content-Type'))->toBe('video/mp4')
        ->and($full->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and($full->headers->get('Content-Disposition'))->toBeNull();

    $part = $this->actingAs($this->owner)->get("/designs/{$video->id}/play", ['Range' => 'bytes=10-19'])->assertStatus(206);

    expect($part->streamedContent())->toBe('0123456789')
        ->and($part->headers->get('Content-Range'))->toBe('bytes 10-19/'.strlen($bytes));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/files/fileClip') && $request->hasHeader('Range', 'bytes=10-19'));

    expect(DesignThumbnail::query()->count())->toBe(0);
});

test('only videos play, and only for members of the workspace', function () {
    $this->actingAs($this->owner)->get("/designs/{$this->design->id}/play")->assertNotFound();

    $video = designsMake($this->workspace, ['drive_file_id' => 'fileClip', 'mime_type' => 'video/mp4', 'kind' => 'video']);
    $this->actingAs(workspaceOutsider($this->workspace))->get("/designs/{$video->id}/play")->assertNotFound();
});
