<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Support;

use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Marmara\Inbox\Exceptions\InboxProviderException;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;

/**
 * The channel's token, refreshed first through TryPost's ConnectionVerifier
 * when it is about to expire (the same rule the analytics collectors use).
 */
class InboxAccessToken
{
    public static function for(SocialAccount $account): string
    {
        if ($account->needsProactiveTokenRefresh()) {
            try {
                app(ConnectionVerifier::class)->refreshToken($account);
            } catch (TokenExpiredException $exception) {
                throw new InboxProviderException('authentication', $exception->getMessage());
            } catch (PlatformUnavailableException $exception) {
                throw new InboxProviderException('transient', $exception->getMessage());
            }
        }

        $token = (string) $account->access_token;

        if ($token === '') {
            throw new InboxProviderException('authentication', 'the channel has no access token: reconnect it in TryPost');
        }

        return $token;
    }
}
