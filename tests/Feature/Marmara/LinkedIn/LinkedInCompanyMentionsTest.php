<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Marmara\LinkedIn\Mentions\LinkedInOrganizationResolver;
use App\Marmara\LinkedIn\Mentions\MentionAwareContentSanitizer;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\ContentSanitizer;
use App\Services\Social\LinkedInPagePublisher;
use App\Services\Social\LinkedInPublisher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const LI_MENTION_LOOKUP = 'https://api.linkedin.com/rest/organizations*';

beforeEach(function () {
    config()->set('marmara-linkedin.api', 'https://api.linkedin.com');
    config()->set('marmara-linkedin.version', '202610');
    config()->set('marmara-linkedin.daily_call_budget', 80);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
});

function liMentionAccount(Workspace $workspace, string $network): SocialAccount
{
    return $network === 'linkedin-page'
        ? SocialAccount::factory()->linkedinPage()->create([
            'workspace_id' => $workspace->id,
            'platform_user_id' => '104857600',
            'access_token' => 'li-page-token',
            'token_expires_at' => now()->addDays(60),
            'meta' => ['organization_id' => '104857600'],
        ])
        : SocialAccount::factory()->linkedin()->create([
            'workspace_id' => $workspace->id,
            'platform_user_id' => 'member123',
            'access_token' => 'li-page-token',
            'token_expires_at' => now()->addDays(60),
        ]);
}

function liMentionPost(SocialAccount $account, User $user, string $content): Post
{
    $post = Post::factory()->forAccount($account)->create([
        'user_id' => $user->id,
        'content' => $content,
        'content_type' => $account->platform === Platform::LinkedInPage ? ContentType::LinkedInPagePost : ContentType::LinkedInPost,
        'meta' => ['link_preview' => false],
    ]);
    $post->setRelation('socialAccount', $account);

    return $post;
}

function liMentionPublisher(string $network): LinkedInPublisher|LinkedInPagePublisher
{
    return $network === 'linkedin-page' ? new LinkedInPagePublisher : new LinkedInPublisher;
}

/**
 * The organization lookup stub first, then the post creation, so a generic
 * stub never answers the lookup.
 *
 * @param  array<string, mixed>|null  $organization
 */
function liMentionFake(string $network, ?array $organization = ['id' => 123, 'localizedName' => 'Akbank', 'vanityName' => 'akbank'], int $status = 200): void
{
    Http::fake([
        LI_MENTION_LOOKUP => $status === 200
            ? Http::response(['elements' => $organization === null ? [] : [$organization]])
            : Http::response(['message' => 'Not enough permissions to access: organizations.FINDER-vanityName', 'status' => $status], $status),
        config("trypost.platforms.{$network}.api").'/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:7400000000000000001']),
    ]);
}

/**
 * @return list<string>
 */
function liMentionCommentaries(): array
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/rest/posts'))
        ->map(fn (array $pair): string => (string) $pair[0]['commentary'])
        ->values()
        ->all();
}

function liMentionLookups(): int
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/rest/organizations'))->count();
}

dataset('linkedin mention networks', ['linkedin', 'linkedin-page']);

test('a resolved company mention becomes a LinkedIn mention and the rest is escaped as before', function (string $network) {
    liMentionFake($network);
    $account = liMentionAccount($this->workspace, $network);

    liMentionPublisher($network)->publish(liMentionPost($account, $this->user, 'Teşekkürler (sponsor) @[Akbank](https://www.linkedin.com/company/akbank/) #fintech'));

    expect(liMentionCommentaries())->toBe(['Teşekkürler \\(sponsor\\) @[Akbank](urn:li:organization:123) #fintech']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.linkedin.com/rest/organizations?q=vanityName&vanityName=akbank'
        && $request->hasHeader('LinkedIn-Version', '202610')
        && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0')
        && $request->hasHeader('Authorization', 'Bearer li-page-token'));
})->with('linkedin mention networks');

test('the mention takes the page name LinkedIn gives and escapes it', function () {
    liMentionFake('linkedin-page', ['id' => 456, 'localizedName' => 'Türk Telekom (Kurumsal)', 'vanityName' => 'turk-telekom']);
    $account = liMentionAccount($this->workspace, 'linkedin-page');

    (new LinkedInPagePublisher)->publish(liMentionPost($account, $this->user, 'Hello @[Türk Telekom](linkedin.com/company/turk-telekom) and @[Marmara_AI](urn:li:organization:789)'));

    expect(liMentionCommentaries())->toBe(['Hello @[Türk Telekom \\(Kurumsal\\)](urn:li:organization:456) and @[Marmara\\_AI](urn:li:organization:789)'])
        // The URN needs no lookup.
        ->and(liMentionLookups())->toBe(1);
});

test('an unresolved company mention publishes the plain name and logs a warning', function (int $status, ?array $organization) {
    Log::spy();
    liMentionFake('linkedin-page', $organization, $status);
    $account = liMentionAccount($this->workspace, 'linkedin-page');

    $result = (new LinkedInPagePublisher)->publish(liMentionPost($account, $this->user, 'Thanks @[Akbank_TR](https://www.linkedin.com/company/akbank) (sponsor)'));

    expect($result['id'])->toBe('urn:li:share:7400000000000000001')
        ->and(liMentionCommentaries())->toBe(['Thanks Akbank\\_TR \\(sponsor\\)']);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'company mention')
        && $context['vanity'] === 'akbank'
        && ! str_contains(json_encode($context), 'li-page-token'));
})->with([
    'no permission' => [403, null],
    'not found' => [200, null],
    'server error' => [500, null],
]);

test('a network failure never fails the publish', function () {
    Http::fake([
        LI_MENTION_LOOKUP => fn () => throw new ConnectionException('timed out'),
        config('trypost.platforms.linkedin-page.api').'/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:7400000000000000002']),
    ]);
    $account = liMentionAccount($this->workspace, 'linkedin-page');

    (new LinkedInPagePublisher)->publish(liMentionPost($account, $this->user, 'Hi @[Akbank](https://www.linkedin.com/company/akbank)'));

    expect(liMentionCommentaries())->toBe(['Hi Akbank']);
});

test('the vanity lookup is cached, found or not', function (int $status, ?array $organization, string $commentary) {
    liMentionFake('linkedin-page', $organization, $status);
    $account = liMentionAccount($this->workspace, 'linkedin-page');

    foreach (['First @[Akbank](https://www.linkedin.com/company/akbank)', 'Second @[Akbank](https://linkedin.com/company/Akbank/) @[Akbank](linkedin.com/company/akbank)'] as $content) {
        (new LinkedInPagePublisher)->publish(liMentionPost($account, $this->user, $content));
    }

    expect(liMentionLookups())->toBe(1)
        ->and(liMentionCommentaries()[1])->toBe($commentary);
})->with([
    'found' => [200, ['id' => 123, 'localizedName' => 'Akbank', 'vanityName' => 'akbank'], 'Second @[Akbank](urn:li:organization:123) @[Akbank](urn:li:organization:123)'],
    'refused' => [403, null, 'Second Akbank Akbank'],
]);

test('a cached miss expires after a short while', function () {
    liMentionFake('linkedin-page', null);
    $account = liMentionAccount($this->workspace, 'linkedin-page');

    (new LinkedInPagePublisher)->publish(liMentionPost($account, $this->user, 'A @[Akbank](linkedin.com/company/akbank)'));
    $this->travel(16)->minutes();
    (new LinkedInPagePublisher)->publish(liMentionPost($account, $this->user, 'B @[Akbank](linkedin.com/company/akbank)'));

    expect(liMentionLookups())->toBe(2);
});

test('a refused lookup is cached for that channel only: another channel with its own token still resolves within the window', function () {
    Http::fake([
        LI_MENTION_LOOKUP => fn (Request $request) => $request->hasHeader('Authorization', 'Bearer token-a')
            ? Http::response(['message' => 'Not enough permissions to access: organizations.FINDER-vanityName', 'status' => 403], 403)
            : Http::response(['elements' => [['id' => 123, 'localizedName' => 'Akbank', 'vanityName' => 'akbank']]]),
        config('trypost.platforms.linkedin-page.api').'/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:7400000000000000003']),
    ]);
    $channelA = liMentionAccount($this->workspace, 'linkedin-page');
    $channelA->update(['access_token' => 'token-a']);
    $channelB = SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '104857601',
        'access_token' => 'token-b',
        'token_expires_at' => now()->addDays(60),
        'meta' => ['organization_id' => '104857601'],
    ]);
    $content = 'Hi @[Akbank](https://www.linkedin.com/company/akbank)';

    (new LinkedInPagePublisher)->publish(liMentionPost($channelA->fresh(), $this->user, $content));
    (new LinkedInPagePublisher)->publish(liMentionPost($channelA->fresh(), $this->user, $content));
    $this->travel(5)->minutes();
    (new LinkedInPagePublisher)->publish(liMentionPost($channelB, $this->user, $content));
    // Now found for everyone, channel A included, without another call.
    (new LinkedInPagePublisher)->publish(liMentionPost($channelA->fresh(), $this->user, $content));

    expect(liMentionCommentaries())->toBe(['Hi Akbank', 'Hi Akbank', 'Hi @[Akbank](urn:li:organization:123)', 'Hi @[Akbank](urn:li:organization:123)'])
        ->and(liMentionLookups())->toBe(2);
});

test('without a channel id a refused lookup is cached per token, and the token is never stored', function () {
    Http::fake([
        LI_MENTION_LOOKUP => fn (Request $request) => $request->hasHeader('Authorization', 'Bearer token-a')
            ? Http::response(['message' => 'Not enough permissions'], 403)
            : Http::response(['elements' => [['id' => 123, 'localizedName' => 'Akbank', 'vanityName' => 'akbank']]]),
    ]);
    $resolver = app(LinkedInOrganizationResolver::class);

    expect($resolver->resolve('akbank', 'token-a')->status)->toBe(403)
        ->and($resolver->resolve('akbank', 'token-a')->status)->toBe(403)
        ->and($resolver->resolve('akbank', 'token-b')->id)->toBe('123')
        ->and(liMentionLookups())->toBe(2)
        ->and(serialize(Cache::store()->getStore()))->not->toContain('token-a');
});

test('every other network gets the display name', function (Platform $platform) {
    $sanitizer = app(ContentSanitizer::class);
    $content = 'Thanks @[Akbank](https://www.linkedin.com/company/akbank) and @[Marmara AI](urn:li:organization:12)';

    expect($sanitizer)->toBeInstanceOf(MentionAwareContentSanitizer::class)
        ->and($sanitizer->sanitize($content, $platform))->toBe('Thanks Akbank and Marmara AI')
        ->and($sanitizer->displayText($content, $platform))->toBe('Thanks Akbank and Marmara AI');
})->with([
    Platform::Instagram,
    Platform::X,
    Platform::Facebook,
    Platform::TikTok,
    Platform::Threads,
    Platform::Mastodon,
    Platform::Bluesky,
    Platform::LinkedIn,
    Platform::LinkedInPage,
]);

test('only a LinkedIn post keeps the marker for its publisher, and its length counts the name', function () {
    $content = 'Thanks @[Akbank](https://www.linkedin.com/company/akbank)';
    $linkedIn = liMentionPost(liMentionAccount($this->workspace, 'linkedin-page'), $this->user, $content);
    $instagram = Post::factory()->forAccount(SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]))->create([
        'user_id' => $this->user->id,
        'content' => $content,
        'content_type' => ContentType::InstagramFeed,
    ]);
    $sanitizer = app(ContentSanitizer::class);

    expect($sanitizer->forPost($linkedIn))->toBe($content)
        ->and($sanitizer->forPost($instagram))->toBe('Thanks Akbank')
        ->and(mb_strlen($sanitizer->displayText($content, Platform::LinkedInPage)))->toBe(mb_strlen('Thanks Akbank'))
        ->and($sanitizer->plainText($content))->toBe('Thanks Akbank');
});

test('marmara:linkedin-org prints the organization or the error status', function () {
    liMentionAccount($this->workspace, 'linkedin-page');
    liMentionFake('linkedin-page');

    $this->artisan('marmara:linkedin-org', ['vanity' => 'https://www.linkedin.com/company/akbank/'])
        ->expectsOutputToContain('Organization: urn:li:organization:123')
        ->expectsOutputToContain('Name: Akbank')
        ->assertSuccessful();
});

test('marmara:linkedin-org reports a refused lookup', function () {
    liMentionAccount($this->workspace, 'linkedin-page');
    liMentionFake('linkedin-page', null, 403);

    $this->artisan('marmara:linkedin-org', ['vanity' => 'akbank'])
        ->expectsOutputToContain('HTTP 403')
        ->assertFailed();
});
