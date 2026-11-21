<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Drive;

use App\Marmara\Designs\DesignsConfig;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google's OAuth 2.0 JWT bearer flow for the service account
 * (https://developers.google.com/identity/protocols/oauth2/service-account#httprest):
 * an RS256 assertion signed with the key file's private key (firebase/php-jwt,
 * already installed with google/apiclient; it signs with openssl_sign), traded
 * for an access token that is cached until shortly before it expires.
 * Neither the key nor the token is ever logged.
 */
class ServiceAccountToken
{
    public const string SCOPE = 'https://www.googleapis.com/auth/drive';

    private const int EXPIRY_MARGIN_SECONDS = 120;

    public function __construct(private readonly DesignsConfig $config) {}

    public function accessToken(): string
    {
        $key = $this->key();
        $cacheKey = 'marmara-designs:sa-token:'.sha1($key['client_email'].'|'.self::SCOPE);
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        ['token' => $token, 'expires_in' => $expiresIn] = $this->requestToken($key);

        Cache::put($cacheKey, $token, max(60, $expiresIn - self::EXPIRY_MARGIN_SECONDS));

        return $token;
    }

    public function forget(): void
    {
        try {
            $key = $this->key();
        } catch (DriveException) {
            return;
        }

        Cache::forget('marmara-designs:sa-token:'.sha1($key['client_email'].'|'.self::SCOPE));
    }

    public function clientEmail(): ?string
    {
        try {
            return $this->key()['client_email'];
        } catch (DriveException) {
            return null;
        }
    }

    /**
     * The signed assertion (exposed for tests and `marmara:designs:status`).
     *
     * @param  array{client_email: string, private_key: string, private_key_id: ?string, token_uri: string}  $key
     */
    public function assertion(array $key, ?int $now = null): string
    {
        $now ??= time();

        return JWT::encode([
            'iss' => $key['client_email'],
            'scope' => self::SCOPE,
            'aud' => $key['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ], $key['private_key'], 'RS256', $key['private_key_id']);
    }

    /**
     * @return array{client_email: string, private_key: string, private_key_id: ?string, token_uri: string}
     */
    public function key(): array
    {
        $path = $this->config->serviceAccountKeyPath();

        if ($path === null) {
            throw DriveException::configuration('MARMARA_DESIGNS_SA_KEY is not set.');
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw DriveException::configuration("The service account key file is not readable at {$path}.");
        }

        $json = json_decode((string) file_get_contents($path), true);

        if (! is_array($json) || ($json['type'] ?? null) !== 'service_account' || ! is_string($json['client_email'] ?? null) || ! is_string($json['private_key'] ?? null)) {
            throw DriveException::configuration('The service account key file is not a Google service account JSON key.');
        }

        return [
            'client_email' => $json['client_email'],
            'private_key' => $json['private_key'],
            'private_key_id' => is_string($json['private_key_id'] ?? null) ? $json['private_key_id'] : null,
            'token_uri' => is_string($json['token_uri'] ?? null) && $json['token_uri'] !== '' ? $json['token_uri'] : $this->config->tokenUrl(),
        ];
    }

    /**
     * @param  array{client_email: string, private_key: string, private_key_id: ?string, token_uri: string}  $key
     * @return array{token: string, expires_in: int}
     */
    private function requestToken(array $key): array
    {
        try {
            $assertion = $this->assertion($key);
        } catch (Throwable) {
            throw DriveException::configuration('The service account private key could not sign the token request.');
        }

        $response = Http::asForm()->acceptJson()->timeout(20)->post($key['token_uri'], [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);

        $token = (string) $response->json('access_token', '');

        if (! $response->successful() || $token === '') {
            throw new DriveException("Google refused the service account token request ({$response->status()}: {$response->json('error', 'unknown')}).", $response->status(), (string) $response->json('error'));
        }

        return ['token' => $token, 'expires_in' => (int) $response->json('expires_in', 3600)];
    }
}
