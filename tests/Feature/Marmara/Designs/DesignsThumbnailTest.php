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
