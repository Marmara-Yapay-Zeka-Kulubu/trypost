<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Drive;

use App\Marmara\Designs\DesignsConfig;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Drive v3 REST calls (https://developers.google.com/drive/api/reference/rest/v3)
 * with Laravel's HTTP client. By default it acts as the service account; the
 * uploader makes one with the lead's own drive.file token (withToken()).
 * Ids are validated before they go into a URL; the token only travels in the
 * Authorization header and is never logged.
 */
class DriveClient
{
    public const string FOLDER_MIME = 'application/vnd.google-apps.folder';

    public const string ID_PATTERN = '/^[A-Za-z0-9_-]{1,200}$/';

    public const string FILE_FIELDS = 'id,name,mimeType,size,md5Checksum,parents,trashed,createdTime,modifiedTime,owners(displayName,emailAddress),lastModifyingUser(displayName,emailAddress),webViewLink,headRevisionId,imageMediaMetadata(width,height),videoMediaMetadata(width,height),appProperties';

    public const string REVISION_FIELDS = 'id,mimeType,modifiedTime,keepForever,size,md5Checksum,originalFilename,lastModifyingUser(displayName,emailAddress)';

    /** @var (Closure(): string)|null */
    private ?Closure $token = null;

    /** @var (Closure(): void)|null */
    private ?Closure $onUnauthorized = null;

    public function __construct(
        private readonly DesignsConfig $config,
        private readonly ServiceAccountToken $serviceAccount,
    ) {}

    /**
     * The same client with another identity (the lead's OAuth token).
     *
     * @param  Closure(): string  $token
     * @param  (Closure(): void)|null  $onUnauthorized  called once on a 401, before one retry
     */
    public function withToken(Closure $token, ?Closure $onUnauthorized = null): self
    {
        $client = clone $this;
        $client->token = $token;
        $client->onUnauthorized = $onUnauthorized;

        return $client;
    }

    public static function validId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function file(string $id, string $fields = self::FILE_FIELDS): array
    {
        return $this->json('get', 'files/'.$this->id($id), ['fields' => $fields, 'supportsAllDrives' => 'true']);
    }

    /**
     * One page of a folder's children (not trashed).
     *
     * @return array{files: list<array<string, mixed>>, nextPageToken: ?string}
     */
    public function children(string $folderId, ?string $pageToken = null, ?string $extraQuery = null): array
    {
        $query = "'".$this->id($folderId)."' in parents and trashed = false".($extraQuery !== null ? " and {$extraQuery}" : '');
        $json = $this->json('get', 'files', array_filter([
            'q' => $query,
            'fields' => 'nextPageToken,files('.self::FILE_FIELDS.')',
            'pageSize' => 1000,
            'pageToken' => $pageToken,
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
            'orderBy' => 'createdTime',
        ]));

        return ['files' => array_values(array_filter((array) ($json['files'] ?? []), 'is_array')), 'nextPageToken' => self::stringOrNull($json['nextPageToken'] ?? null)];
    }

    public function startPageToken(): string
    {
        $token = self::stringOrNull($this->json('get', 'changes/startPageToken', ['supportsAllDrives' => 'true'])['startPageToken'] ?? null);

        if ($token === null) {
            throw new DriveException('Drive gave no startPageToken.', 502);
        }

        return $token;
    }

    /**
     * One page of changes since the token.
     *
     * @return array{changes: list<array<string, mixed>>, nextPageToken: ?string, newStartPageToken: ?string}
     */
    public function changes(string $pageToken): array
    {
        $json = $this->json('get', 'changes', [
            'pageToken' => $pageToken,
            'pageSize' => 500,
            'includeRemoved' => 'true',
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
            'spaces' => 'drive',
            'fields' => 'nextPageToken,newStartPageToken,changes(changeType,fileId,removed,time,file('.self::FILE_FIELDS.'))',
        ]);

        return [
            'changes' => array_values(array_filter((array) ($json['changes'] ?? []), 'is_array')),
            'nextPageToken' => self::stringOrNull($json['nextPageToken'] ?? null),
            'newStartPageToken' => self::stringOrNull($json['newStartPageToken'] ?? null),
        ];
    }

    /**
     * Every revision of a file, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function revisions(string $fileId): array
    {
        $revisions = [];
        $pageToken = null;

        do {
            $json = $this->json('get', 'files/'.$this->id($fileId).'/revisions', array_filter([
                'fields' => 'nextPageToken,revisions('.self::REVISION_FIELDS.')',
                'pageSize' => 200,
                'pageToken' => $pageToken,
            ]));
            $revisions = [...$revisions, ...array_values(array_filter((array) ($json['revisions'] ?? []), 'is_array'))];
            $pageToken = self::stringOrNull($json['nextPageToken'] ?? null);
        } while ($pageToken !== null);

        return $revisions;
    }

    /**
     * Marks a revision keepForever=true, so Drive does not purge it after 30
     * days or 100 revisions. Binary files only; needs edit access.
     *
     * @return array<string, mixed>
     */
    public function keepRevisionForever(string $fileId, string $revisionId): array
    {
        return $this->json('patch', 'files/'.$this->id($fileId).'/revisions/'.$this->id($revisionId).'?fields='.rawurlencode(self::REVISION_FIELDS), [], ['keepForever' => true]);
    }

    /**
     * The file's content (or a revision's), whole. Callers check the size first.
     */
    public function download(string $fileId, ?string $revisionId = null): string
    {
        return $this->send('get', $this->contentPath($fileId, $revisionId), ['alt' => 'media', 'supportsAllDrives' => 'true'])->body();
    }

    /**
     * The content as a stream, for the original download (never stored).
     */
    public function stream(string $fileId, ?string $revisionId = null, ?string $exportMime = null): StreamInterface
    {
        $path = $exportMime !== null ? 'files/'.$this->id($fileId).'/export' : $this->contentPath($fileId, $revisionId);
        $query = $exportMime !== null ? ['mimeType' => $exportMime] : ['alt' => 'media', 'supportsAllDrives' => 'true'];

        return $this->send('get', $path, $query, null, ['stream' => true])->toPsrResponse()->getBody();
    }

    /**
     * The current content as a stream, with Drive's own status and headers,
     * so a byte range (`bytes=0-1023`) asked by a <video> comes back as 206
     * with its Content-Range. Nothing is buffered here.
     */
    public function media(string $fileId, ?string $range = null): ResponseInterface
    {
        $headers = $range !== null ? ['Range' => $range] : [];

        return $this->send('get', $this->contentPath($fileId, null), ['alt' => 'media', 'supportsAllDrives' => 'true'], null, ['stream' => true], $headers)->toPsrResponse();
    }

    /**
     * A Google Workspace file (a Drawing) exported, e.g. as image/png.
     */
    public function export(string $fileId, string $mimeType): string
    {
        return $this->send('get', 'files/'.$this->id($fileId).'/export', ['mimeType' => $mimeType])->body();
    }

    /**
     * Drive's own thumbnail at about the given width. thumbnailLink is
     * short-lived and private, so it is read fresh and fetched with the token.
     */
    public function thumbnail(string $fileId, int $size): ?string
    {
        $link = self::stringOrNull($this->file($fileId, 'thumbnailLink')['thumbnailLink'] ?? null);

        if ($link === null) {
            return null;
        }

        $sized = preg_match('/=s\d+(-[a-z0-9-]+)?$/i', $link) === 1
            ? (string) preg_replace('/=s\d+(-[a-z0-9-]+)?$/i', "=s{$size}", $link)
            : "{$link}=s{$size}";

        try {
            $response = $this->request()->withToken($this->currentToken())->timeout(30)->get($sized);
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() && $response->body() !== '' ? $response->body() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function moveFile(string $fileId, string $addParent, string $removeParent): array
    {
        return $this->json('patch', 'files/'.$this->id($fileId).'?'.http_build_query([
            'addParents' => $this->id($addParent),
            'removeParents' => $this->id($removeParent),
            'supportsAllDrives' => 'true',
            'fields' => self::FILE_FIELDS,
        ]), [], (object) []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findChildFolder(string $parentId, string $name): ?array
    {
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $name);
        $files = $this->children($parentId, null, "mimeType = '".self::FOLDER_MIME."' and name = '{$escaped}'")['files'];

        return $files[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function createFolder(string $parentId, string $name): array
    {
        return $this->json('post', 'files?'.http_build_query(['supportsAllDrives' => 'true', 'fields' => self::FILE_FIELDS]), [], [
            'name' => $name,
            'mimeType' => self::FOLDER_MIME,
            'parents' => [$this->id($parentId)],
        ]);
    }

    /**
     * Resumable upload (https://developers.google.com/drive/api/guides/manage-uploads#resumable):
     * the metadata first, then the bytes to the session URL.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function upload(array $metadata, string $bytes, string $mimeType): array
    {
        $start = $this->send('post', $this->config->uploadApi().'/files?'.http_build_query([
            'uploadType' => 'resumable',
            'supportsAllDrives' => 'true',
            'fields' => self::FILE_FIELDS,
        ]), [], $metadata, [], [
            'X-Upload-Content-Type' => $mimeType,
            'X-Upload-Content-Length' => (string) strlen($bytes),
        ]);

        $session = $start->header('Location');

        if ($session === '' || ! str_starts_with($session, $this->config->uploadApi())) {
            throw new DriveException('Drive gave no upload session.', 502);
        }

        try {
            $response = $this->request()
                ->withToken($this->currentToken())
                ->timeout(300)
                ->withBody($bytes, $mimeType)
                ->put($session);
        } catch (ConnectionException $exception) {
            throw new DriveException('Drive did not answer the upload: '.$exception->getMessage());
        }

        if (! $response->successful()) {
            throw self::failure($response);
        }

        return (array) $response->json();
    }

    /**
     * About the identity behind the token (the connected lead's email).
     *
     * @return array<string, mixed>
     */
    public function about(): array
    {
        return $this->json('get', 'about', ['fields' => 'user(displayName,emailAddress)']);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|object|null  $body
     * @return array<string, mixed>
     */
    private function json(string $method, string $path, array $query = [], array|object|null $body = null): array
    {
        return (array) $this->send($method, $path, $query, $body)->json();
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|object|null  $body
     * @param  array<string, mixed>  $options
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $path, array $query = [], array|object|null $body = null, array $options = [], array $headers = [], bool $retried = false): Response
    {
        $url = str_starts_with($path, 'https://') || str_starts_with($path, 'http://') ? $path : $this->config->driveApi().'/'.$path;

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        try {
            $request = $this->request()->withToken($this->currentToken())->timeout(60)->withOptions($options)->withHeaders($headers);
            $response = match ($method) {
                'get' => $request->get($url),
                'patch' => $request->asJson()->patch($url, $body ?? []),
                'post' => $request->asJson()->post($url, $body ?? []),
                'delete' => $request->delete($url),
            };
        } catch (ConnectionException $exception) {
            throw new DriveException('Drive did not answer: '.$exception->getMessage());
        }

        if ($response->status() === 401 && ! $retried) {
            $this->refreshIdentity();

            return $this->send($method, $path, $query, $body, $options, $headers, true);
        }

        if (! $response->successful()) {
            throw self::failure($response);
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->connectTimeout(10);
    }

    private function currentToken(): string
    {
        return $this->token !== null ? ($this->token)() : $this->serviceAccount->accessToken();
    }

    private function refreshIdentity(): void
    {
        if ($this->token !== null) {
            if ($this->onUnauthorized !== null) {
                ($this->onUnauthorized)();
            }

            return;
        }

        $this->serviceAccount->forget();
    }

    private function contentPath(string $fileId, ?string $revisionId): string
    {
        return 'files/'.$this->id($fileId).($revisionId !== null ? '/revisions/'.$this->id($revisionId) : '');
    }

    private function id(string $id): string
    {
        if (! self::validId($id)) {
            throw new DriveException('Invalid Drive id.', 400);
        }

        return $id;
    }

    private static function failure(Response $response): DriveException
    {
        $reason = data_get($response->json(), 'error.errors.0.reason') ?? data_get($response->json(), 'error.status');
        $message = (string) (data_get($response->json(), 'error.message') ?? 'Drive error');

        return new DriveException("Drive answered {$response->status()}: {$message}", $response->status(), is_string($reason) ? $reason : null);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
