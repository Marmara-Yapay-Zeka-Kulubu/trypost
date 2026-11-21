<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Upload;

use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Drive\DriveClient;
use App\Marmara\Designs\Drive\DriveException;
use App\Marmara\Designs\Models\DesignDriveConnection;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * The optional "Drive'a yükleme izni": Google's OAuth web-server flow with
 * the `drive.file` scope, offline (a refresh token), PKCE (S256). The token
 * is stored encrypted on DesignDriveConnection and refreshed before it
 * expires; a refresh Google refuses (invalid_grant: revoked, or the
 * OAuth app still in "Testing", whose tokens die after 7 days) disconnects.
 */
class DriveUploadAuth
{
    public const string SCOPE = 'https://www.googleapis.com/auth/drive.file';

    public function __construct(
        private readonly DesignsConfig $config,
        private readonly DriveClient $drive,
    ) {}

    public function authorizeUrl(string $state, string $codeChallenge): string
    {
        return $this->config->oauthAuthorizeUrl().'?'.http_build_query([
            'client_id' => (string) $this->config->oauthClientId(),
            'redirect_uri' => $this->config->oauthRedirect(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], encoding_type: PHP_QUERY_RFC3986);
    }

    /**
     * Trades the code, reads who connected and stores the connection
     * (folder permission still to be given with the Picker).
     */
    public function connect(Workspace $workspace, User $user, string $code, string $verifier): DesignDriveConnection
    {
        $response = Http::asForm()->acceptJson()->timeout(20)->post($this->config->tokenUrl(), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => (string) $this->config->oauthClientId(),
            'client_secret' => (string) $this->config->oauthClientSecret(),
            'redirect_uri' => $this->config->oauthRedirect(),
        ]);

        $accessToken = (string) $response->json('access_token', '');

        if (! $response->successful() || $accessToken === '') {
            throw new DriveException('Google code exchange failed ('.$response->status().': '.$response->json('error', 'unknown').').', $response->status(), (string) $response->json('error'));
        }

        $refreshToken = (string) $response->json('refresh_token', '');

        if ($refreshToken === '') {
            throw new DriveException((string) __('marmara-designs::designs.drive.no_refresh_token'), 400, 'no_refresh_token');
        }

        $scopes = array_values(array_filter(explode(' ', (string) $response->json('scope', ''))));
        $about = $this->drive->withToken(fn (): string => $accessToken)->about();

        return DesignDriveConnection::query()->updateOrCreate(['workspace_id' => $workspace->id], [
            'user_id' => $user->id,
            'google_email' => data_get($about, 'user.emailAddress'),
            'google_name' => data_get($about, 'user.displayName'),
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => CarbonImmutable::now()->addSeconds(max(60, (int) $response->json('expires_in', 3600))),
            'scopes' => $scopes,
            'folder_granted_at' => null,
            'last_error' => null,
        ]);
    }

    /**
     * A Drive client acting as the connected lead (drive.file).
     */
    public function client(DesignDriveConnection $connection): DriveClient
    {
        return $this->drive->withToken(
            fn (): string => $this->accessToken($connection),
            fn () => $this->refresh($connection),
        );
    }

    public function accessToken(DesignDriveConnection $connection): string
    {
        if ($connection->access_token !== null && $connection->expires_at !== null && $connection->expires_at->gt(CarbonImmutable::now()->addMinute())) {
            return $connection->access_token;
        }

        return $this->refresh($connection);
    }

    public function refresh(DesignDriveConnection $connection): string
    {
        if ($connection->refresh_token === null) {
            throw new DesignUploadUnavailable('reconnect');
        }

        $response = Http::asForm()->acceptJson()->timeout(20)->post($this->config->tokenUrl(), [
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
            'client_id' => (string) $this->config->oauthClientId(),
            'client_secret' => (string) $this->config->oauthClientSecret(),
        ]);

        $token = (string) $response->json('access_token', '');

        if ($response->json('error') === 'invalid_grant') {
            $connection->forceFill(['access_token' => null, 'refresh_token' => null, 'expires_at' => null, 'last_error' => 'invalid_grant'])->save();

            throw new DesignUploadUnavailable('reconnect');
        }

        if (! $response->successful() || $token === '') {
            throw new DriveException("Google refused the token refresh ({$response->status()}).", $response->status(), (string) $response->json('error'));
        }

        $connection->forceFill([
            'access_token' => $token,
            'expires_at' => CarbonImmutable::now()->addSeconds(max(60, (int) $response->json('expires_in', 3600))),
            'last_error' => null,
        ])->save();

        return $token;
    }

    /**
     * After the lead picked the folder in the Google Picker: checks that it
     * is the configured folder and that this token may now add files to it.
     */
    public function grantFolder(DesignDriveConnection $connection, string $folderId): bool
    {
        if ($folderId !== $this->config->folderId()) {
            return false;
        }

        try {
            $folder = $this->client($connection)->file($folderId, 'id,mimeType,capabilities(canAddChildren)');
        } catch (DriveException) {
            return false;
        }

        if (! (bool) data_get($folder, 'capabilities.canAddChildren', false)) {
            return false;
        }

        $connection->forceFill(['folder_granted_at' => CarbonImmutable::now()])->save();

        return true;
    }

    public function disconnect(DesignDriveConnection $connection): void
    {
        $token = $connection->refresh_token ?? $connection->access_token;

        if ($token !== null) {
            rescue(fn () => Http::asForm()->timeout(10)->post($this->config->oauthRevokeUrl(), ['token' => $token]), report: false);
        }

        $connection->delete();
    }
}
