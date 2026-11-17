<?php

declare(strict_types=1);

use App\Marmara\Zernio\ZernioConfig;

/*
|--------------------------------------------------------------------------
| Marmara AI fork: social inbox (comments and DMs)
|--------------------------------------------------------------------------
|
| Owned by the App\Marmara\Inbox module (see app/Marmara/README.md), not by
| upstream TryPost. With `enabled` off nothing in TryPost changes: no sync,
| no page, no API routes, no MCP tools, no extra OAuth scopes. Each provider
| has its own switch on top of it.
|
*/

$list = fn (string $name, string $default): array => array_values(array_filter(array_map('trim', explode(',', (string) env($name, $default)))));

return [

    'enabled' => (bool) env('MARMARA_INBOX_ENABLED', false),

    'providers' => [
        // LinkedIn page comments (Community Management API, shares the LinkedIn module's call budget).
        'linkedin' => (bool) env('MARMARA_INBOX_LINKEDIN', false),
        // Instagram comments and DMs (both TryPost Instagram connections).
        'instagram' => (bool) env('MARMARA_INBOX_INSTAGRAM', false),
        // Facebook Page comments and Messenger.
        'facebook' => (bool) env('MARMARA_INBOX_FACEBOOK', false),
        // Zernio's inbox (TikTok and every account mapped below). May be a paid Zernio add-on.
        'zernio' => (bool) env('MARMARA_INBOX_ZERNIO', false),
        // X mentions (and DMs with dm.read/dm.write) through TryPost's own X connection. Pay-per-use API.
        'x' => (bool) env('MARMARA_INBOX_X', false),
    ],

    /*
     * Extra OAuth scopes TryPost's connect asks for while the inbox and the
     * provider are on. A channel gets them after "Refresh connection". Trim a
     * list (for example drop the messaging scope) while Meta has not granted it.
     */
    'scopes' => [
        'instagram' => $list('MARMARA_INBOX_INSTAGRAM_SCOPES', 'instagram_business_manage_comments,instagram_business_manage_messages'),
        'instagram-facebook' => $list('MARMARA_INBOX_INSTAGRAM_FACEBOOK_SCOPES', 'instagram_manage_comments,instagram_manage_messages,pages_manage_metadata'),
        'facebook' => $list('MARMARA_INBOX_FACEBOOK_SCOPES', 'pages_read_user_content,pages_manage_engagement,pages_messaging'),
        'x' => $list('MARMARA_INBOX_X_SCOPES', 'dm.read,dm.write'),
    ],

    /*
     * A new item older than this is stored without the inbox.item_received
     * webhook, so the first sync of a channel does not flood Echo.
     */
    'notify_max_age_hours' => (int) env('MARMARA_INBOX_NOTIFY_MAX_AGE_HOURS', 24),

    // Pages a sync job reads per channel and kind before it hands over to the next run.
    'max_pages_per_sync' => (int) env('MARMARA_INBOX_MAX_PAGES_PER_SYNC', 3),

    // Posts younger than this are polled for comments.
    'post_window_days' => (int) env('MARMARA_INBOX_POST_WINDOW_DAYS', 14),

    'linkedin' => [
        'interval_minutes' => (int) env('MARMARA_INBOX_LINKEDIN_INTERVAL_MINUTES', 180),
        // Inbox calls per UTC day, taken from (not added to) MARMARA_LINKEDIN_DAILY_CALL_BUDGET.
        'daily_calls' => (int) env('MARMARA_INBOX_LINKEDIN_DAILY_CALLS', 30),
        'posts_per_run' => (int) env('MARMARA_INBOX_LINKEDIN_POSTS_PER_RUN', 3),
    ],

    'meta' => [
        'interval_minutes' => (int) env('MARMARA_INBOX_META_INTERVAL_MINUTES', 10),
        'posts_per_run' => (int) env('MARMARA_INBOX_META_POSTS_PER_RUN', 5),
        // After a Graph rate-limit answer without a usable estimate.
        'backoff_minutes' => (int) env('MARMARA_INBOX_META_BACKOFF_MINUTES', 30),
    ],

    'zernio' => [
        'interval_minutes' => (int) env('MARMARA_INBOX_ZERNIO_INTERVAL_MINUTES', 10),
        'posts_per_run' => (int) env('MARMARA_INBOX_ZERNIO_POSTS_PER_RUN', 5),
        /*
         * MARMARA_INBOX_ZERNIO_ACCOUNTS="instagram:marmaraai=<zernio id>,x:<trypost account>=<zernio id>"
         * Channels whose inbox comes from Zernio even when a native provider
         * could read them. TikTok and LinkedIn pages mapped in ZERNIO_ACCOUNTS
         * are used too (when no native provider reads them).
         */
        'accounts' => ZernioConfig::parseAccounts((string) env('MARMARA_INBOX_ZERNIO_ACCOUNTS', '')),
        // Signing secret of the optional Zernio webhook receiver; empty = receiver off (polling only).
        'webhook_secret' => env('MARMARA_INBOX_ZERNIO_WEBHOOK_SECRET'),
    ],

    'x' => [
        'interval_minutes' => (int) env('MARMARA_INBOX_X_INTERVAL_MINUTES', 30),
    ],

];
