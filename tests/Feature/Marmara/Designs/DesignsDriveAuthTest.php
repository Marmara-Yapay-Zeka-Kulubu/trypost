<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use App\Marmara\Designs\Drive\DriveClient;
use App\Marmara\Designs\Drive\DriveException;
use App\Marmara\Designs\Drive\ServiceAccountToken;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    ['workspace' => $workspace] = createApiTestToken();
    designsConfigure($workspace);
});

test('the service account signs an RS256 jwt bearer assertion and caches the token until it expires', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.sa-token', 'expires_in' => 3599, 'token_type' => 'Bearer']),
    ]);
    $key = designsServiceAccountKey();

    expect(app(ServiceAccountToken::class)->accessToken())->toBe('ya29.sa-token')
        ->and(app(ServiceAccountToken::class)->accessToken())->toBe('ya29.sa-token');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($key): bool {
        $assertion = (string) $request['assertion'];
        [$header] = explode('.', $assertion);
        $claims = (array) JWT::decode($assertion, new Key($key['public_key'], 'RS256'));

        return $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && json_decode(base64_decode(strtr($header, '-_', '+/')), true) == ['typ' => 'JWT', 'alg' => 'RS256', 'kid' => 'kid-123']
            && $claims['iss'] === $key['client_email']
            && $claims['scope'] === 'https://www.googleapis.com/auth/drive'
            && $claims['aud'] === 'https://oauth2.googleapis.com/token'
            && $claims['exp'] - $claims['iat'] === 3600;
    });
});

test('the token is asked again once it expired', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::sequence()
            ->push(['access_token' => 'first', 'expires_in' => 600])
            ->push(['access_token' => 'second', 'expires_in' => 600]),
    ]);

    expect(app(ServiceAccountToken::class)->accessToken())->toBe('first');

    $this->travel(479)->seconds();
    expect(app(ServiceAccountToken::class)->accessToken())->toBe('first');

    $this->travel(2)->seconds();
    expect(app(ServiceAccountToken::class)->accessToken())->toBe('second');
});

test('drive calls carry the bearer token and a 401 gets a fresh token once', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::sequence()
            ->push(['access_token' => 'stale', 'expires_in' => 3599])
            ->push(['access_token' => 'fresh', 'expires_in' => 3599]),
        'www.googleapis.com/drive/v3/changes/startPageToken*' => Http::sequence()
            ->push(['error' => ['code' => 401, 'message' => 'Invalid Credentials']], 401)
            ->push(['kind' => 'drive#startPageToken', 'startPageToken' => '987']),
    ]);

    expect(app(DriveClient::class)->startPageToken())->toBe('987');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'startPageToken') && $request->hasHeader('Authorization', 'Bearer fresh'));
});

test('a refused token request and a missing key file are reported, never with the key', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400)]);

    expect(fn () => app(ServiceAccountToken::class)->accessToken())->toThrow(DriveException::class, 'invalid_grant');

    config()->set('marmara-designs.service_account_key', '/nonexistent/key.json');
    expect(fn () => app(ServiceAccountToken::class)->accessToken())->toThrow(DriveException::class, 'not readable');
});
