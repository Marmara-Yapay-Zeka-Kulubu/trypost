<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn;

use App\Enums\SocialAccount\Platform;
use App\Marmara\Zernio\ZernioConfig;
use App\Models\SocialAccount;

/**
 * Decides, per channel, whether a LinkedIn page is measured natively.
 *
 * Native when the module is on, the channel is a LinkedIn page, it is NOT
 * mapped to Zernio (ZERNIO_ACCOUNTS, while ZERNIO_ENABLED), it has its
 * organization id and a native token (connected through TryPost's LinkedIn
 * OAuth, not the placeholder `marmara:zernio:link` stores). Everything else
 * keeps the previous behaviour (Zernio, or upstream's "unsupported"), so
 * Zernio and the native collectors never measure the same channel.
 *
 * Whether the token still works is not part of the choice: an expired or
 * revoked token is reported by the collectors as `authentication` (and a
 * missing scope as `permission`), like upstream collectors do.
 */
class NativeLinkedInAccounts
{
    public function __construct(private readonly LinkedInConfig $config) {}

    public function uses(?SocialAccount $account): bool
    {
        return $this->reason($account) === null;
    }

    /**
     * Why the channel is not measured natively, or null when it is.
     */
    public function reason(?SocialAccount $account): ?string
    {
        return match (true) {
            ! $this->config->enabled() => 'MARMARA_LINKEDIN_NATIVE_ANALYTICS is off',
            $account === null || $account->platform !== Platform::LinkedInPage => 'not a LinkedIn page channel',
            $this->zernioMapped($account) => 'mapped to Zernio in ZERNIO_ACCOUNTS (Zernio measures it)',
            self::organizationId($account) === null => 'no LinkedIn organization id (meta.organization_id)',
            ! self::hasNativeToken($account) => 'no native LinkedIn token (connect the page through TryPost)',
            default => null,
        };
    }

    public static function organizationId(SocialAccount $account): ?string
    {
        $id = data_get($account->meta, 'organization_id');

        return is_scalar($id) && preg_match('/^(?:urn:li:organization:)?(\d+)$/', trim((string) $id), $matches) === 1
            ? $matches[1]
            : null;
    }

    public static function organizationUrn(SocialAccount $account): ?string
    {
        $id = self::organizationId($account);

        return $id === null ? null : "urn:li:organization:{$id}";
    }

    /**
     * A token TryPost's LinkedIn OAuth stored, not the placeholder of a
     * channel created from Zernio (`meta.managed_by=zernio`).
     */
    public static function hasNativeToken(SocialAccount $account): bool
    {
        $token = (string) $account->access_token;

        return data_get($account->meta, 'managed_by') !== 'zernio'
            && $token !== ''
            && ! str_starts_with($token, 'zernio-managed:');
    }

    private function zernioMapped(SocialAccount $account): bool
    {
        return class_exists(ZernioConfig::class)
            && app(ZernioConfig::class)->zernioAccountFor($account) !== null;
    }
}
