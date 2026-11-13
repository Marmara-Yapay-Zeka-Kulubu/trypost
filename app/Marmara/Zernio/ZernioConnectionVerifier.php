<?php

declare(strict_types=1);

namespace App\Marmara\Zernio;

use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;
use Illuminate\Support\Facades\Log;

/**
 * TryPost's connection checks (VerifyWorkspaceConnections,
 * VerifyUpcomingPostConnections, RefreshSocialToken) decide whether a
 * channel is `connected`. For a Zernio-mapped channel that is Zernio's
 * account health, not the native token: a healthy Zernio account keeps (or
 * brings back) the channel connected, a disconnected one expires it. Every
 * other channel keeps the native checks.
 */
class ZernioConnectionVerifier extends ConnectionVerifier
{
    public function __construct(
        private readonly ZernioConfig $config,
        private readonly ZernioAccountHealth $health,
    ) {}

    public function verify(SocialAccount $account): bool
    {
        return $this->zernioAccount($account) === null
            ? parent::verify($account)
            : $this->verifyThroughZernio($account);
    }

    public function verifyAccessToken(SocialAccount $account): bool
    {
        return $this->zernioAccount($account) === null
            ? parent::verifyAccessToken($account)
            : $this->verifyThroughZernio($account);
    }

    /**
     * A mapped channel's native token is not used. It is still refreshed
     * when it can be (so moving the channel back to the native path finds a
     * live token), but a refused refresh no longer expires the channel. A
     * channel created by `marmara:zernio:link` has no native token at all.
     */
    public function refreshToken(SocialAccount $account): bool
    {
        if ($this->zernioAccount($account) === null) {
            return parent::refreshToken($account);
        }

        if (self::isZernioOnly($account)) {
            return false;
        }

        try {
            return parent::refreshToken($account);
        } catch (TokenExpiredException $e) {
            Log::info('Marmara Zernio: native token refresh refused for a Zernio channel; ignored', [
                'account_id' => $account->id,
                'platform' => $account->platform->value,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public static function isZernioOnly(SocialAccount $account): bool
    {
        return data_get($account->meta, 'managed_by') === 'zernio';
    }

    private function verifyThroughZernio(SocialAccount $account): bool
    {
        $zernioAccountId = (string) $this->zernioAccount($account);

        return match ($this->health->status($zernioAccountId, ZernioConfig::zernioPlatform($account->platform))) {
            ZernioAccountHealth::HEALTHY => true,
            ZernioAccountHealth::UNHEALTHY => throw new TokenExpiredException("The Zernio account {$zernioAccountId} needs to be reconnected in Zernio."),
            ZernioAccountHealth::MISSING => throw new TokenExpiredException("The Zernio account {$zernioAccountId} is not connected to Zernio."),
            default => throw new PlatformUnavailableException('Zernio could not be asked about the account.'),
        };
    }

    private function zernioAccount(SocialAccount $account): ?string
    {
        return $this->config->zernioAccountFor($account);
    }
}
