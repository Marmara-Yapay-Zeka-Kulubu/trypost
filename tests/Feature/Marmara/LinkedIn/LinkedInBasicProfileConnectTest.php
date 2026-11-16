<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Marmara\LinkedIn\Connect\BasicProfileLinkedInProvider;
use App\Marmara\LinkedIn\NativeLinkedInAccounts;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\PendingConnection;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;

const LI_BASIC_ORG = '104857600';

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));

    config()->set('marmara-linkedin.page_basic_profile', true);
    config()->set('trypost.platforms.linkedin.enabled', false);
    config()->set('trypost.platforms.linkedin-page.enabled', true);
    config()->set('services.linkedin-openid', [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect' => 'https://trypost.test/accounts/linkedin/callback',
    ]);
});

/**
 * The scopes the connect route sends the member to LinkedIn with.
 *
 * @return list<string>
 */
function liBasicConnectScopes(object $test): array
{
    $location = (string) $test->actingAs($test->user)->get(route('app.social.linkedin.connect'))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return array_values(array_filter(explode(' ', (string) ($query['scope'] ?? ''))));
}

/**
 * Runs the real `linkedin-openid` driver against a fake LinkedIn: the token
 * exchange, then /v2/me. Organizations come from the Http fake, like upstream.
 *
 * @param  list<array<string, mixed>>  $organizations
 * @param  array<int, array<string, mixed>>  $history
 */
function liBasicCallback(object $test, array $organizations, array &$history): void
{
    $stack = HandlerStack::create(new MockHandler([
        new PsrResponse(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'access_token' => 'native-token',
            'expires_in' => 5184000,
            'refresh_token' => 'native-refresh',
            'refresh_token_expires_in' => 31536000,
            'scope' => implode(',', BasicProfileLinkedInProvider::PAGE_SCOPES),
        ])),
        new PsrResponse(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'AbC123',
            'localizedFirstName' => 'Ayşe',
            'localizedLastName' => 'Yılmaz',
        ])),
    ]));
    $stack->push(Middleware::history($history));

    Socialite::driver('linkedin-openid')
        ->stateless()
        ->setRequest(Request::create('/accounts/linkedin/callback', 'GET', ['code' => 'auth-code']))
        ->setHttpClient(new Client(['handler' => $stack]));

    Http::fake([
        config('trypost.platforms.linkedin.api').'/v2/organizationAcls*' => Http::response([
            'elements' => array_map(fn (array $organization): array => ['organization~' => $organization], $organizations),
        ]),
    ]);

    $test->actingAs($test->user)
        ->get(route('app.social.linkedin.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::LinkedIn));
}

test('the linkedin-openid driver is the basic profile provider and asks exactly the page scopes', function () {
    expect(Socialite::driver('linkedin-openid'))->toBeInstanceOf(BasicProfileLinkedInProvider::class)
        ->and(liBasicConnectScopes($this))->toBe(BasicProfileLinkedInProvider::PAGE_SCOPES);

    // LINKEDIN_PAGE_SCOPES with the OpenID scopes in it is ignored while the switch is on.
    config()->set('trypost.platforms.linkedin-page.scopes', ['openid', 'profile', 'email', 'w_organization_social']);

    expect(liBasicConnectScopes($this))->toBe(BasicProfileLinkedInProvider::PAGE_SCOPES);

    // The personal profile keeps its own scopes, without openid/profile/email.
    config()->set('trypost.platforms.linkedin.enabled', true);
    config()->set('trypost.platforms.linkedin.scopes', ['openid', 'profile', 'email', 'w_member_social']);

    expect(liBasicConnectScopes($this))->toBe([...BasicProfileLinkedInProvider::PAGE_SCOPES, 'w_member_social']);
});

test('with the switch off the driver asks the OpenID scopes like upstream', function () {
    config()->set('marmara-linkedin.page_basic_profile', false);

    expect(liBasicConnectScopes($this))->toContain('openid', 'profile', 'email', 'rw_organization_admin')
        ->not->toContain('r_basicprofile');
});

test('the callback reads the member from /v2/me and stores the page with normalized scopes', function () {
    startSocialConnect($this->workspace, Platform::LinkedIn);
    $history = [];

    liBasicCallback($this, [['id' => (int) LI_BASIC_ORG, 'localizedName' => 'Marmara Yapay Zeka Kulübü', 'vanityName' => 'marmaraai']], $history);

    expect(PendingConnection::current()->identityKeys())->toBe(['linkedin-page:'.LI_BASIC_ORG]);

    assertFinishedOnChannel(finishSocialConnect(Platform::LinkedIn, ['linkedin-page:'.LI_BASIC_ORG]));

    $account = $this->workspace->socialAccounts()->sole();

    expect($account->platform)->toBe(Platform::LinkedInPage)
        ->and($account->platform_user_id)->toBe(LI_BASIC_ORG)
        ->and($account->username)->toBe('marmaraai')
        ->and($account->access_token)->toBe('native-token')
        ->and($account->refresh_token)->toBe('native-refresh')
        ->and($account->token_expires_at)->not->toBeNull()
        ->and($account->scopes)->toBe(BasicProfileLinkedInProvider::PAGE_SCOPES)
        ->and((string) data_get($account->meta, 'organization_id'))->toBe(LI_BASIC_ORG)
        ->and(data_get($account->meta, 'admin_user_id'))->toBe('AbC123')
        ->and(data_get($account->meta, 'admin_name'))->toBe('Ayşe Yılmaz');

    expect($history)->toHaveCount(2)
        ->and((string) $history[0]['request']->getUri())->toBe('https://www.linkedin.com/oauth/v2/accessToken')
        ->and((string) $history[0]['request']->getBody())->toContain('code=auth-code')
        ->and($history[1]['request']->getUri()->getPath())->toBe('/v2/me')
        ->and(urldecode($history[1]['request']->getUri()->getQuery()))->toContain('projection=(id,localizedFirstName,localizedLastName)')
        ->and($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer native-token');
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v2/userinfo'));
});

test('Refresh connection on the Zernio-linked page card updates that same card', function () {
    $account = SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => LI_BASIC_ORG,
        'username' => 'marmaraai',
        'access_token' => 'zernio-managed:placeholder',
        'refresh_token' => null,
        'token_expires_at' => null,
        'scopes' => null,
        'meta' => ['organization_id' => LI_BASIC_ORG, 'managed_by' => 'zernio', 'zernio_account_id' => '7b1c2d3e4f5a6b7c8d9e0f1a'],
        'status' => Status::Connected,
    ]);
    $history = [];

    startSocialConnect($this->workspace, Platform::LinkedIn, $account);
    liBasicCallback($this, [
        ['id' => (int) LI_BASIC_ORG, 'localizedName' => 'Marmara Yapay Zeka Kulübü'],
        ['id' => 222, 'localizedName' => 'Other Company'],
    ], $history);

    expect(PendingConnection::current()->identityKeys())->toBe(['linkedin-page:'.LI_BASIC_ORG]);

    finishSocialConnect(Platform::LinkedIn)->assertRedirect();

    $fresh = $account->fresh();

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($fresh->access_token)->toBe('native-token')
        ->and($fresh->refresh_token)->toBe('native-refresh')
        ->and($fresh->scopes)->toBe(BasicProfileLinkedInProvider::PAGE_SCOPES)
        ->and(data_get($fresh->meta, 'managed_by'))->toBeNull()
        ->and(data_get($fresh->meta, 'admin_user_id'))->toBe('AbC123')
        ->and(NativeLinkedInAccounts::hasNativeToken($fresh))->toBeTrue();
});
