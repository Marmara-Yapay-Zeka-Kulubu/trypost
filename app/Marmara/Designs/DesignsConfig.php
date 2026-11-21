<?php

declare(strict_types=1);

namespace App\Marmara\Designs;

use App\Models\Workspace;

/**
 * Reads config/marmara-designs.php with sane bounds. Read on every call, never
 * cached at boot, so tests and `config:cache` behave the same.
 */
class DesignsConfig
{
    public function enabled(): bool
    {
        return (bool) config('marmara-designs.enabled', false);
    }

    public function folderId(): ?string
    {
        return $this->string('marmara-designs.folder_id');
    }

    public function serviceAccountKeyPath(): ?string
    {
        return $this->string('marmara-designs.service_account_key');
    }

    /**
     * The workspace the designs belong to: the configured one, else the only
     * workspace of the install.
     */
    public function workspace(): ?Workspace
    {
        $id = $this->string('marmara-designs.workspace_id');

        if ($id !== null) {
            return Workspace::query()->find($id);
        }

        $workspaces = Workspace::query()->limit(2)->get();

        return $workspaces->count() === 1 ? $workspaces->first() : null;
    }

    /**
     * The module runs only when it is on and its workspace is known (the
     * configured one, or the install's only workspace).
     */
    public function available(): bool
    {
        return $this->enabled() && $this->workspace() !== null;
    }

    /**
     * Whether a workspace is the designs workspace (and the module runs).
     */
    public function isDesignsWorkspace(?Workspace $workspace): bool
    {
        return $workspace !== null && $this->enabled() && $this->workspace()?->id === $workspace->id;
    }

    public function driveApi(): string
    {
        return (string) config('marmara-designs.drive_api', 'https://www.googleapis.com/drive/v3');
    }

    public function uploadApi(): string
    {
        return (string) config('marmara-designs.upload_api', 'https://www.googleapis.com/upload/drive/v3');
    }

    public function tokenUrl(): string
    {
        return (string) config('marmara-designs.token_url', 'https://oauth2.googleapis.com/token');
    }

    public function oauthClientId(): ?string
    {
        return $this->string('marmara-designs.oauth.client_id');
    }

    public function oauthClientSecret(): ?string
    {
        return $this->string('marmara-designs.oauth.client_secret');
    }

    public function oauthRedirect(): string
    {
        return $this->string('marmara-designs.oauth.redirect') ?? route('marmara.designs.drive.callback');
    }

    public function oauthConfigured(): bool
    {
        return $this->oauthClientId() !== null && $this->oauthClientSecret() !== null;
    }

    public function oauthAuthorizeUrl(): string
    {
        return (string) config('marmara-designs.oauth.authorize_url', 'https://accounts.google.com/o/oauth2/v2/auth');
    }

    public function oauthRevokeUrl(): string
    {
        return (string) config('marmara-designs.oauth.revoke_url', 'https://oauth2.googleapis.com/revoke');
    }

    /**
     * @return array{api_key: ?string, app_id: ?string, sdk: string}
     */
    public function picker(): array
    {
        return [
            'api_key' => $this->string('marmara-designs.oauth.picker_api_key'),
            'app_id' => $this->string('marmara-designs.oauth.picker_app_id'),
            'sdk' => (string) config('marmara-designs.oauth.picker_sdk', 'https://apis.google.com/js/api.js'),
        ];
    }

    public function uploadMaxBytes(): int
    {
        return max(1, (int) config('marmara-designs.upload_max_mb', 100)) * 1024 * 1024;
    }

    public function maxChangePages(): int
    {
        return min(100, max(1, (int) config('marmara-designs.max_change_pages', 20)));
    }

    public function keepRevisions(): bool
    {
        return (bool) config('marmara-designs.keep_revisions', true);
    }

    public function notifyMaxAgeHours(): int
    {
        return max(0, (int) config('marmara-designs.notify_max_age_hours', 24));
    }

    public function thumbnailPath(): string
    {
        return rtrim((string) config('marmara-designs.thumbnails.path', storage_path('app/marmara-designs/thumbnails')), '/');
    }

    public function thumbnailCacheMaxBytes(): int
    {
        return max(1, (int) config('marmara-designs.thumbnails.max_mb', 500)) * 1024 * 1024;
    }

    /**
     * @return list<int>
     */
    public function thumbnailSizes(): array
    {
        $sizes = array_values(array_filter(array_map('intval', (array) config('marmara-designs.thumbnails.sizes', [400, 1600])), fn (int $size): bool => $size >= 32 && $size <= 4000));

        return $sizes === [] ? [400, 1600] : $sizes;
    }

    public function thumbnailQuality(): int
    {
        return min(95, max(30, (int) config('marmara-designs.thumbnails.quality', 70)));
    }

    public function signedUrlDays(): int
    {
        return min(30, max(1, (int) config('marmara-designs.thumbnails.signed_url_days', 7)));
    }

    public function thumbnailSourceMaxBytes(): int
    {
        return max(1, (int) config('marmara-designs.thumbnails.source_max_mb', 40)) * 1024 * 1024;
    }

    private function string(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
