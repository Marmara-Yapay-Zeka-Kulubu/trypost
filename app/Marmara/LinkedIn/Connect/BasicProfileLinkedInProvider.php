<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Connect;

use App\Enums\SocialAccount\Platform;
use GuzzleHttp\RequestOptions;
use Laravel\Socialite\Two\User;
use SocialiteProviders\LinkedIn\Provider;

/**
 * TryPost's `linkedin-openid` Socialite driver for a LinkedIn app that only
 * has the Community Management API product (no "Sign In with LinkedIn using
 * OpenID Connect"): with MARMARA_LINKEDIN_PAGE_BASIC_PROFILE on, the consent
 * screen asks for `r_basicprofile` and the page scopes instead of
 * openid/profile/email, and the member comes from `GET /v2/me` instead of the
 * OpenID userinfo endpoint. The member id is the same in both (the person
 * id), so `meta.admin_user_id` and existing personal cards keep matching.
 * There is no email: nothing in TryPost's LinkedIn connect uses it.
 *
 * With the switch off every method is the parent's (upstream behaviour).
 */
class BasicProfileLinkedInProvider extends Provider
{
    /** Exactly what the page connect asks for with the switch on. */
    public const array PAGE_SCOPES = [
        'r_basicprofile',
        'w_organization_social',
        'r_organization_social',
        'rw_organization_admin',
        'r_organization_followers',
        'r_organization_social_feed',
        // Inbox replies as the page (app/Marmara/README.md, Inbox): needs "Refresh connection".
        'w_organization_social_feed',
    ];

    private const array OPENID_SCOPES = ['openid', 'profile', 'email'];

    public static function enabled(): bool
    {
        return (bool) config('marmara-linkedin.page_basic_profile', false);
    }

    /**
     * The page scopes, plus the personal profile's own scopes (without
     * openid/profile/email) while LINKEDIN_ENABLED is on. LINKEDIN_PAGE_SCOPES
     * and the OpenID defaults of the parent are not used.
     *
     * @return array<int, string>
     */
    public function getScopes()
    {
        if (! self::enabled()) {
            return parent::getScopes();
        }

        $personal = Platform::LinkedIn->isEnabled()
            ? array_diff((array) config('trypost.platforms.linkedin.scopes', []), self::OPENID_SCOPES)
            : [];

        return array_values(array_unique([...self::PAGE_SCOPES, ...$personal]));
    }

    /**
     * @param  string  $token
     * @return array<string, mixed>
     */
    protected function getUserByToken($token)
    {
        if (! self::enabled()) {
            return parent::getUserByToken($token);
        }

        $response = $this->getHttpClient()->get(rtrim((string) config('trypost.platforms.linkedin.api', 'https://api.linkedin.com'), '/').'/v2/me', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$token,
                'X-RestLi-Protocol-Version' => '2.0.0',
            ],
            RequestOptions::QUERY => [
                'projection' => '(id,localizedFirstName,localizedLastName)',
            ],
            RequestOptions::TIMEOUT => 15,
        ]);

        return (array) json_decode((string) $response->getBody(), true);
    }

    /**
     * @param  array<string, mixed>  $user
     * @return User
     */
    protected function mapUserToObject(array $user)
    {
        if (array_key_exists('sub', $user) || ! array_key_exists('id', $user)) {
            return parent::mapUserToObject($user);
        }

        $first = is_string($user['localizedFirstName'] ?? null) ? $user['localizedFirstName'] : null;
        $last = is_string($user['localizedLastName'] ?? null) ? $user['localizedLastName'] : null;
        $name = trim(($first ?? '').' '.($last ?? ''));

        return (new User)->setRaw($user)->map([
            'id' => (string) $user['id'],
            'nickname' => null,
            'name' => $name !== '' ? $name : null,
            'first_name' => $first,
            'last_name' => $last,
            'email' => null,
            'email_verified' => null,
            'avatar' => null,
            'avatar_original' => null,
        ]);
    }
}
