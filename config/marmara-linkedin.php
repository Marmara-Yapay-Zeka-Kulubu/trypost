<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Marmara AI fork: native LinkedIn page analytics
|--------------------------------------------------------------------------
|
| Owned by the App\Marmara\LinkedIn module (see app/Marmara/README.md), not by
| upstream TryPost. With `native_analytics` off nothing in TryPost changes.
| With it on, a LinkedIn page channel connected through TryPost's own
| LinkedIn OAuth (native token + organization id) and NOT mapped in
| ZERNIO_ACCOUNTS is measured through LinkedIn's Community Management API.
| A mapped channel keeps Zernio.
|
*/

return [

    'native_analytics' => (bool) env('MARMARA_LINKEDIN_NATIVE_ANALYTICS', false),

    /*
     * Connect LinkedIn pages without the "Sign In with LinkedIn using OpenID
     * Connect" product: ask r_basicprofile + the page scopes instead of
     * openid/profile/email and read the member from /v2/me.
     */
    'page_basic_profile' => (bool) env('MARMARA_LINKEDIN_PAGE_BASIC_PROFILE', false),

    'api' => rtrim((string) env('MARMARA_LINKEDIN_API', 'https://api.linkedin.com'), '/'),

    /*
     * LinkedIn-Version header (YYYYMM). LinkedIn supports a version for about
     * a year; 202601 sunsets on 2027-01-15.
     */
    'version' => (string) env('MARMARA_LINKEDIN_API_VERSION', '202610'),

    'timeout' => (int) env('MARMARA_LINKEDIN_TIMEOUT', 20),

    'connect_timeout' => (int) env('MARMARA_LINKEDIN_CONNECT_TIMEOUT', 5),

    /*
     * Calls this module may make per UTC day (all of its reads together).
     * Development tier: ~500 per app and ~100 per member per day, reset at
     * midnight UTC. The backfill command stops `backfill_reserve` calls
     * before the budget, leaving them for the scheduled reads.
     */
    'daily_call_budget' => (int) env('MARMARA_LINKEDIN_DAILY_CALL_BUDGET', 80),

    'backfill_reserve' => (int) env('MARMARA_LINKEDIN_BACKFILL_RESERVE', 20),

    // After a 429 without Retry-After, no call is made for this long.
    'backoff_minutes' => (int) env('MARMARA_LINKEDIN_BACKOFF_MINUTES', 60),

    // Post URNs per organizationalEntityShareStatistics request (no pagination there).
    'stats_batch_size' => (int) env('MARMARA_LINKEDIN_STATS_BATCH_SIZE', 50),

    // How long a batch's per-post stats (and a video's views) are reused.
    'cache_minutes' => (int) env('MARMARA_LINKEDIN_CACHE_MINUTES', 720),

    // Posts per posts-finder page (LinkedIn allows up to 100).
    'page_size' => (int) env('MARMARA_LINKEDIN_PAGE_SIZE', 50),

    /*
     * TryPost's discovery runs every PUBLICATION_DISCOVERY_INTERVAL_HOURS (3);
     * LinkedIn's post list is read at most this often per page. Posts
     * published from TryPost are linked without it.
     */
    'discovery_interval_hours' => (int) env('MARMARA_LINKEDIN_DISCOVERY_INTERVAL_HOURS', 12),

    // Video views are read for video posts up to this age (LinkedIn keeps ~6 months).
    'video_views_max_age_days' => (int) env('MARMARA_LINKEDIN_VIDEO_VIEWS_MAX_AGE_DAYS', 180),

    // Newest unlinked publications marmara:linkedin:analytics-backfill imports into Sent.
    'import_limit' => (int) env('MARMARA_LINKEDIN_IMPORT_LIMIT', 500),

];
