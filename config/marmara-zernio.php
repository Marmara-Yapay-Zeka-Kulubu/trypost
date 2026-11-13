<?php

declare(strict_types=1);

use App\Marmara\Zernio\ZernioConfig;

/*
|--------------------------------------------------------------------------
| Marmara AI fork: TikTok publishing through Zernio
|--------------------------------------------------------------------------
|
| Owned by the App\Marmara\Zernio module (see app/Marmara/README.md), not by
| upstream TryPost. With `enabled` off nothing in TryPost changes. With it on,
| posts of the mapped TryPost accounts (TikTok, LinkedIn Page) are published
| through Zernio's audited apps instead of TryPost's own apps.
|
*/

// ZERNIO_ACCOUNTS="tiktok:marmaraai=<zernio id>,linkedin-page:<trypost account>=<zernio id>"
$mapped = ZernioConfig::parseAccounts((string) env('ZERNIO_ACCOUNTS', ''));

// Older TikTok-only variables, still read.
$accounts = ZernioConfig::parsePairs((string) env('ZERNIO_TIKTOK_ACCOUNTS', ''));

if (filled(env('ZERNIO_TIKTOK_TRYPOST_ACCOUNT')) && filled(env('ZERNIO_TIKTOK_ACCOUNT_ID'))) {
    $accounts[trim((string) env('ZERNIO_TIKTOK_TRYPOST_ACCOUNT'))] = trim((string) env('ZERNIO_TIKTOK_ACCOUNT_ID'));
}

return [

    'enabled' => (bool) env('ZERNIO_ENABLED', false),

    'api_key' => env('ZERNIO_API_KEY'),

    'base_url' => rtrim((string) env('ZERNIO_BASE_URL', 'https://zernio.com/api/v1'), '/'),

    'timeout' => (int) env('ZERNIO_TIMEOUT', 120),

    /*
     * TryPost platform value => [TryPost account => Zernio account id], from
     * ZERNIO_ACCOUNTS. Supported: tiktok, linkedin-page. The TryPost account
     * is the social account id (UUID), the username (with or without @) or
     * the platform user id. A channel not listed (or whose entry is removed)
     * goes back to TryPost's native publishing and analytics.
     */
    'accounts' => $mapped,

    'tiktok' => [

        /*
         * TryPost TikTok account => Zernio account id (`_id` from
         * `php artisan marmara:zernio:accounts`). The key is the TryPost
         * social account id (UUID), the TikTok username (with or without @)
         * or the TikTok platform user id. Accounts not listed keep
         * publishing natively, so another workspace's TikTok never posts
         * through the club's Zernio account.
         */
        'accounts' => $accounts,

        /*
         * `upload`: copy each media file to Zernio's storage (presign + PUT)
         * before the post. `url`: hand Zernio the public TryPost media URL.
         */
        'media_mode' => env('ZERNIO_MEDIA_MODE', 'upload'),

        /*
         * While TikTok is still processing, the status is checked again
         * every `status_poll_seconds` until `status_window_minutes` pass.
         */
        'status_poll_seconds' => (int) env('ZERNIO_STATUS_POLL_SECONDS', 60),

        'status_window_minutes' => (int) env('ZERNIO_STATUS_WINDOW_MINUTES', 180),
    ],

    /*
     * Analytics of the mapped TikTok accounts read from Zernio (post history,
     * post metrics, followers) instead of TikTok's API. Needs ZERNIO_ENABLED.
     * `import_limit`: how many of the newest unlinked publications
     * `marmara:zernio:analytics-backfill` imports into Sent (the scheduled
     * pipeline keeps EXTERNAL_POSTS_IMPORT_LIMIT).
     */
    'analytics' => [
        'enabled' => (bool) env('ZERNIO_ANALYTICS_ENABLED', true),
        'page_size' => (int) env('ZERNIO_ANALYTICS_PAGE_SIZE', 50),
        'cache_minutes' => (int) env('ZERNIO_ANALYTICS_CACHE_MINUTES', 360),
        'import_limit' => (int) env('ZERNIO_ANALYTICS_IMPORT_LIMIT', 500),
    ],

];
