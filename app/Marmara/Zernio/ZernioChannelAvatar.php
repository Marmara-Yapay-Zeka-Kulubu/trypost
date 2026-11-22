<?php

declare(strict_types=1);

namespace App\Marmara\Zernio;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The avatar of a channel linked to a Zernio account: Zernio's
 * `profilePicture` (the field `GET /v1/accounts` lists), copied to TryPost's
 * storage like a native connect does, since TikTok's picture URLs are signed
 * and expire. A download that fails at link time used to leave the channel
 * without an avatar for good; the connection checks now fetch it again (at
 * most once a day) while the stored one is missing.
 *
 * @see https://docs.zernio.com/accounts/list-accounts
 */
class ZernioChannelAvatar
{
    /** How long a refresh attempt waits before the next one, whatever its outcome. */
    private const int RETRY_SECONDS = 86400;

    /**
     * Zernio's picture copied to storage: the stored path, or null when Zernio
     * lists none or it cannot be downloaded.
     *
     * @param  array<string, mixed>  $zernio
     */
    public static function download(array $zernio): ?string
    {
        $picture = data_get($zernio, 'profilePicture');

        return is_string($picture) && $picture !== '' ? uploadFromUrl($picture) : null;
    }

    /**
     * Whether the channel has no avatar that loads: none stored, a remote URL
     * (the first `marmara:zernio:link` stored Zernio's URL as is), or a path
     * whose file is gone.
     */
    public static function missing(SocialAccount $account): bool
    {
        $stored = $account->getRawOriginal('avatar_url');

        if (! is_string($stored) || $stored === '') {
            return true;
        }

        if (preg_match('#^https?://#i', $stored) === 1) {
            return true;
        }

        try {
            return ! Storage::exists($stored);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Stores Zernio's picture as the channel's avatar when its own is
     * missing. Never throws: a failure leaves the channel as it was and is
     * tried again a day later.
     *
     * @param  array<string, mixed>|null  $zernio
     */
    public static function refreshIfMissing(SocialAccount $account, ?array $zernio): bool
    {
        if ($zernio === null || ! Cache::add(self::cacheKey($account), true, self::RETRY_SECONDS)) {
            return false;
        }

        try {
            if (! self::missing($account)) {
                return false;
            }

            $path = self::download($zernio);

            if ($path === null) {
                return false;
            }

            $account->forceFill(['avatar_url' => $path])->save();

            return true;
        } catch (Throwable $e) {
            Log::warning('Marmara Zernio: channel avatar refresh failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private static function cacheKey(SocialAccount $account): string
    {
        return "marmara-zernio:avatar-refresh:{$account->id}";
    }
}
