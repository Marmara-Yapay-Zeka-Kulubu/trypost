<?php

declare(strict_types=1);

use App\Marmara\Designs\Models\Design;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| Shared by the Designs tests (required with require_once: Pest only loads
| *Test.php files). Drive answers are shaped like the Drive v3 reference:
| files.list, changes.list, changes.getStartPageToken, revisions.list.
*/

const DESIGNS_ROOT = 'rootFolder01';

/**
 * A throwaway service account key (generated once per run) written to a
 * temporary JSON key file.
 *
 * @return array{path: string, public_key: string, client_email: string}
 */
function designsServiceAccountKey(): array
{
    static $key = null;

    if ($key !== null && is_file($key['path'])) {
        return $key;
    }

    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $private);
    $public = openssl_pkey_get_details($resource)['key'];
    $path = tempnam(sys_get_temp_dir(), 'marmara-sa-').'.json';

    file_put_contents($path, json_encode([
        'type' => 'service_account',
        'project_id' => 'marmara-test',
        'private_key_id' => 'kid-123',
        'private_key' => $private,
        'client_email' => 'trypost-designs@marmara-test.iam.gserviceaccount.com',
        'client_id' => '1234567890',
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ]));

    return $key = ['path' => $path, 'public_key' => $public, 'client_email' => 'trypost-designs@marmara-test.iam.gserviceaccount.com'];
}

function designsConfigure(Workspace $workspace): void
{
    config()->set('marmara-designs.enabled', true);
    config()->set('marmara-designs.workspace_id', $workspace->id);
    config()->set('marmara-designs.folder_id', DESIGNS_ROOT);
    config()->set('marmara-designs.service_account_key', designsServiceAccountKey()['path']);
    config()->set('marmara-designs.thumbnails.path', storage_path('framework/testing/marmara-designs-'.getmypid()));
}

/**
 * A Drive v3 file resource.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function designsDriveFile(string $id, string $name, string $parent, array $overrides = []): array
{
    return [
        'kind' => 'drive#file',
        'id' => $id,
        'name' => $name,
        'mimeType' => 'image/png',
        'parents' => [$parent],
        'trashed' => false,
        'size' => '48213',
        'md5Checksum' => md5($id.$name),
        'createdTime' => '2026-11-20T10:00:00.000Z',
        'modifiedTime' => '2026-11-20T10:05:00.000Z',
        'owners' => [['kind' => 'drive#user', 'displayName' => 'Yusuf Kulüp', 'emailAddress' => 'lead@example.com']],
        'lastModifyingUser' => ['kind' => 'drive#user', 'displayName' => 'Elif Tasarım', 'emailAddress' => 'elif@example.com'],
        'webViewLink' => "https://drive.google.com/file/d/{$id}/view?usp=drivesdk",
        'headRevisionId' => "rev-{$id}-1",
        'imageMediaMetadata' => ['width' => 1080, 'height' => 1350],
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function designsDriveFolder(string $id, string $name, string $parent): array
{
    return [
        'kind' => 'drive#file',
        'id' => $id,
        'name' => $name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parent],
        'trashed' => false,
        'createdTime' => '2026-11-01T09:00:00.000Z',
        'modifiedTime' => '2026-11-01T09:00:00.000Z',
    ];
}

/**
 * Fakes Google: the token endpoint, files.list per parent folder,
 * files.get, changes.getStartPageToken, changes.list per page token and
 * revisions.list / revisions.update per file.
 *
 * @param  array<string, list<array<string, mixed>>>  $children  folder id => children
 * @param  array<string, array<string, mixed>>  $changes  page token => changes.list answer
 * @param  array<string, list<array<string, mixed>>>  $revisions  file id => revisions
 * @param  array<string, string>  $content  "fileId" or "fileId@revisionId" => bytes
 * @param  array<string, string|null>  $thumbnails  thumbnail URL => bytes; a bare file id => null means Drive has no thumbnailLink for it (yet)
 */
function designsFakeDrive(array $children = [], array $changes = [], array $revisions = [], string $startToken = 'token-100', array $content = [], array $thumbnails = []): void
{
    // Http::fake() only adds stubs (the first match answers), so the router
    // is registered once per test and later calls only swap its data.
    $GLOBALS['designsFakeDrive'] = compact('children', 'changes', 'revisions', 'startToken', 'content', 'thumbnails');
    static $registered = null;
    $registered ??= new WeakMap;
    $factory = Http::getFacadeRoot();

    if (isset($registered[$factory])) {
        return;
    }

    $registered[$factory] = true;

    Http::fake(function (Request $request) {
        ['children' => $children, 'changes' => $changes, 'revisions' => $revisions, 'startToken' => $startToken, 'content' => $content, 'thumbnails' => $thumbnails] = $GLOBALS['designsFakeDrive'];
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (str_starts_with($url, 'https://oauth2.googleapis.com/token')) {
            return Http::response(['access_token' => 'sa-access-token', 'expires_in' => 3599, 'token_type' => 'Bearer']);
        }

        if (str_starts_with($url, 'https://lh3.googleusercontent.com/')) {
            return isset($thumbnails[$url]) ? Http::response($thumbnails[$url], 200, ['Content-Type' => 'image/png']) : Http::response('', 404);
        }

        if ($path === '/drive/v3/changes/startPageToken') {
            return Http::response(['kind' => 'drive#startPageToken', 'startPageToken' => $startToken]);
        }

        if ($path === '/drive/v3/changes') {
            $page = $changes[$query['pageToken'] ?? ''] ?? null;

            return $page === null
                ? Http::response(['error' => ['code' => 400, 'message' => 'Invalid Value', 'errors' => [['reason' => 'invalid']]]], 400)
                : Http::response(['kind' => 'drive#changeList', ...$page]);
        }

        if ($path === '/drive/v3/files' && $request->method() === 'GET') {
            preg_match("/'([^']+)' in parents/", (string) ($query['q'] ?? ''), $match);

            return Http::response(['kind' => 'drive#fileList', 'incompleteSearch' => false, 'files' => $children[$match[1] ?? ''] ?? []]);
        }

        if (preg_match('#^/drive/v3/files/([^/]+)/revisions/([^/]+)$#', $path, $match) === 1) {
            if ($request->method() === 'PATCH') {
                return Http::response(['kind' => 'drive#revision', 'id' => $match[2], 'keepForever' => (bool) ($request->data()['keepForever'] ?? false)]);
            }

            $key = "{$match[1]}@{$match[2]}";

            return isset($content[$key]) ? Http::response($content[$key]) : Http::response(['error' => ['code' => 404, 'message' => 'Revision not found']], 404);
        }

        if (preg_match('#^/drive/v3/files/([^/]+)/export$#', $path, $match) === 1) {
            return isset($content["{$match[1]}@export"]) ? Http::response($content["{$match[1]}@export"]) : Http::response(['error' => ['code' => 404, 'message' => 'File not found']], 404);
        }

        if (preg_match('#^/drive/v3/files/([^/]+)/revisions$#', $path, $match) === 1) {
            return Http::response(['kind' => 'drive#revisionList', 'revisions' => $revisions[$match[1]] ?? []]);
        }

        if (preg_match('#^/drive/v3/files/([^/]+)$#', $path, $match) === 1) {
            if (($query['alt'] ?? null) === 'media') {
                if (! isset($content[$match[1]])) {
                    return Http::response(['error' => ['code' => 404, 'message' => 'File not found']], 404);
                }

                $bytes = $content[$match[1]];

                if (preg_match('/^bytes=(\d+)-(\d*)$/', $request->header('Range')[0] ?? '', $range) === 1) {
                    $end = $range[2] === '' ? strlen($bytes) - 1 : min((int) $range[2], strlen($bytes) - 1);
                    $part = substr($bytes, (int) $range[1], $end - (int) $range[1] + 1);

                    return Http::response($part, 206, ['Content-Length' => (string) strlen($part), 'Content-Range' => "bytes {$range[1]}-{$end}/".strlen($bytes)]);
                }

                return Http::response($bytes, 200, ['Content-Length' => (string) strlen($bytes)]);
            }

            if (($query['fields'] ?? null) === 'thumbnailLink') {
                return array_key_exists($match[1], $thumbnails) && $thumbnails[$match[1]] === null
                    ? Http::response(['id' => $match[1]])
                    : Http::response(['thumbnailLink' => "https://lh3.googleusercontent.com/drive-storage/{$match[1]}=s220"]);
            }

            return Http::response(['kind' => 'drive#file', 'id' => $match[1], 'name' => 'Tasarımlar', 'mimeType' => 'application/vnd.google-apps.folder']);
        }

        return Http::response(['error' => ['code' => 404, 'message' => "Unfaked {$request->method()} {$url}"]], 404);
    });
}

/**
 * A stored design row.
 *
 * @param  array<string, mixed>  $attributes
 */
function designsMake(Workspace $workspace, array $attributes = []): Design
{
    static $sequence = 0;
    $sequence++;

    return Design::query()->create([
        'workspace_id' => $workspace->id,
        'drive_file_id' => "file{$sequence}",
        'name' => "Afiş {$sequence}.png",
        'title' => "Afiş {$sequence}",
        'mime_type' => 'image/png',
        'kind' => 'image',
        'size' => 2048,
        'md5' => md5((string) $sequence),
        'folder_drive_id' => DESIGNS_ROOT,
        'folder_path' => '',
        'drive_created_at' => now()->subDays(10 - min($sequence, 9)),
        'drive_modified_at' => now()->subDays(10 - min($sequence, 9)),
        'owner_name' => 'Yusuf Kulüp',
        'owner_email' => 'lead@example.com',
        'web_view_link' => "https://drive.google.com/file/d/file{$sequence}/view",
        'head_revision_id' => "rev-file{$sequence}-1",
        ...$attributes,
    ]);
}

/**
 * A real PNG of the given size (GD), for thumbnail and byte-for-byte tests.
 */
function designsPng(int $width = 1200, int $height = 900): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height, (int) imagecolorallocate($image, 200, 30, 60));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * A real JPEG of the given size (GD).
 */
function designsJpeg(int $width = 1080, int $height = 1350): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, intdiv($width, 3), $height, (int) imagecolorallocate($image, 20, 120, 200));
    ob_start();
    imagejpeg($image, null, 90);

    return (string) ob_get_clean();
}
