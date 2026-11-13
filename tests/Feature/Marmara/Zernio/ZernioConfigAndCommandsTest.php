<?php

declare(strict_types=1);

use App\Marmara\Zernio\ZernioConfig;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('marmara-zernio', [
        'enabled' => true,
        'api_key' => 'sk_test_key',
        'base_url' => 'https://zernio.com/api/v1',
        'timeout' => 30,
        'tiktok' => [
            'accounts' => ['@MarmaraAI' => '66b2e19d8c3f5a7e9d0b1c2d'],
            'media_mode' => 'upload',
            'status_poll_seconds' => 60,
            'status_window_minutes' => 180,
        ],
    ]);
});

test('an account is routed to Zernio by its username, platform user id or TryPost id, and only while enabled', function () {
    $config = app(ZernioConfig::class);
    $byUsername = SocialAccount::factory()->tiktok()->create(['username' => 'marmaraai', 'platform_user_id' => 'tt-1']);
    $other = SocialAccount::factory()->tiktok()->create(['username' => 'someoneelse', 'platform_user_id' => 'tt-2']);

    expect($config->zernioAccountFor($byUsername))->toBe('66b2e19d8c3f5a7e9d0b1c2d')
        ->and($config->zernioAccountFor($other))->toBeNull();

    config()->set('marmara-zernio.tiktok.accounts', [(string) $other->id => '66b2e19d8c3f5a7e9d0b1c2e', 'tt-1' => '66b2e19d8c3f5a7e9d0b1c2f']);

    expect($config->zernioAccountFor($other))->toBe('66b2e19d8c3f5a7e9d0b1c2e')
        ->and($config->zernioAccountFor($byUsername))->toBe('66b2e19d8c3f5a7e9d0b1c2f');

    config()->set('marmara-zernio.enabled', false);

    expect($config->zernioAccountFor($byUsername))->toBeNull();
});

test('the configuration lists what an operator must fix', function () {
    $config = app(ZernioConfig::class);

    expect($config->problems())->toBe([]);

    config()->set('marmara-zernio.api_key', null);
    config()->set('marmara-zernio.tiktok.accounts', []);

    expect($config->problems())->toBe([
        'ZERNIO_API_KEY is empty.',
        'No account is mapped: set ZERNIO_ACCOUNTS (or ZERNIO_TIKTOK_TRYPOST_ACCOUNT + ZERNIO_TIKTOK_ACCOUNT_ID).',
    ]);

    config()->set('marmara-zernio.api_key', 'not-a-key');
    config()->set('marmara-zernio.tiktok.accounts', ['marmaraai' => 'abc']);

    expect($config->problems())->toBe([
        'ZERNIO_API_KEY does not look like a Zernio key (it starts with sk_).',
        'Zernio account id for tiktok:marmaraai should be a 24-character id, got abc.',
    ]);

    config()->set('marmara-zernio.enabled', false);

    expect($config->problems())->toBe([]);
});

test('the status checks fit the configured window', function () {
    config()->set('marmara-zernio.tiktok.status_poll_seconds', 120);
    config()->set('marmara-zernio.tiktok.status_window_minutes', 60);

    expect(app(ZernioConfig::class)->statusMaxChecks())->toBe(30)
        ->and(app(ZernioConfig::class)->statusPollSeconds())->toBe(120);
});

test('marmara:zernio:accounts lists the connected Zernio accounts and their TryPost mapping', function () {
    Http::fake([
        'https://zernio.com/api/v1/accounts*' => Http::response(['accounts' => [
            ['_id' => '66b2e19d8c3f5a7e9d0b1c2d', 'platform' => 'tiktok', 'username' => 'marmaraai', 'displayName' => 'Marmara AI'],
        ], 'hasAnalyticsAccess' => false]),
    ]);

    $this->artisan('marmara:zernio:accounts')
        ->expectsOutputToContain('tiktok:@MarmaraAI')
        ->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://zernio.com/api/v1/accounts'
        && $request->hasHeader('Authorization', 'Bearer sk_test_key'));
});

test('marmara:zernio:test passes with a working key and a connected mapped account', function () {
    Http::fake([
        'https://zernio.com/api/v1/accounts*' => Http::response(['accounts' => [
            ['_id' => '66b2e19d8c3f5a7e9d0b1c2d', 'platform' => 'tiktok'],
        ]]),
    ]);

    $this->artisan('marmara:zernio:test')
        ->expectsOutputToContain('The API key works.')
        ->assertSuccessful();
});

test('marmara:zernio:test fails when Zernio refuses the key', function () {
    Http::fake([
        'https://zernio.com/api/v1/accounts*' => Http::response(['error' => 'Invalid API key', 'code' => 'invalid_credentials'], 401),
    ]);

    $this->artisan('marmara:zernio:test')
        ->expectsOutputToContain('Zernio refused the API key (invalid_credentials).')
        ->assertFailed();
});
