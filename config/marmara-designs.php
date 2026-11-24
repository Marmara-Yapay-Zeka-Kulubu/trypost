<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Marmara AI fork: design gallery ("Tasarımlar")
|--------------------------------------------------------------------------
|
| Owned by the App\Marmara\Designs module (see app/Marmara/README.md), not by
| upstream TryPost. With `enabled` off nothing in TryPost changes: no sync,
| no page, no API routes, no MCP tools, no webhook events.
|
*/

return [

    'enabled' => (bool) env('MARMARA_DESIGNS_ENABLED', false),

    /*
     * The TryPost workspace the designs belong to. Empty: the only workspace
     * of the install (the sync refuses to guess when there are several).
     */
    'workspace_id' => env('MARMARA_DESIGNS_WORKSPACE_ID'),

    // The shared Drive folder (the id at the end of its drive.google.com/drive/folders/… URL).
    'folder_id' => env('MARMARA_DESIGNS_FOLDER_ID'),

    /*
     * Path of the service account's JSON key inside the app container (a
     * read-only mounted secret, never committed). The service account reads
     * the folder and marks revisions "keep forever", so the folder is shared
     * with its client_email as Editor.
     */
    'service_account_key' => env('MARMARA_DESIGNS_SA_KEY'),

    'drive_api' => rtrim((string) env('MARMARA_DESIGNS_DRIVE_API', 'https://www.googleapis.com/drive/v3'), '/'),
    'upload_api' => rtrim((string) env('MARMARA_DESIGNS_DRIVE_UPLOAD_API', 'https://www.googleapis.com/upload/drive/v3'), '/'),
    'token_url' => env('MARMARA_DESIGNS_TOKEN_URL', 'https://oauth2.googleapis.com/token'),

    /*
     * Optional "Drive'a yükleme izni": a Google OAuth client (Web
     * application) so TryPost can upload into the club lead's own Drive with
     * the `drive.file` scope (a service account has no storage quota).
     */
    'oauth' => [
        'client_id' => env('MARMARA_DESIGNS_OAUTH_CLIENT_ID'),
        'client_secret' => env('MARMARA_DESIGNS_OAUTH_CLIENT_SECRET'),
        // Empty: {APP_URL}/designs/drive/callback. Register exactly this URL in the OAuth client.
        'redirect' => env('MARMARA_DESIGNS_OAUTH_REDIRECT'),
        'authorize_url' => env('MARMARA_DESIGNS_OAUTH_AUTHORIZE_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
        'revoke_url' => env('MARMARA_DESIGNS_OAUTH_REVOKE_URL', 'https://oauth2.googleapis.com/revoke'),
        // Google Picker, used once after connecting so drive.file may write into the club folder.
        'picker_api_key' => env('MARMARA_DESIGNS_PICKER_API_KEY'),
        'picker_app_id' => env('MARMARA_DESIGNS_PICKER_APP_ID'),
        'picker_sdk' => env('MARMARA_DESIGNS_PICKER_SDK_URL', 'https://apis.google.com/js/api.js'),
    ],

    // Largest file the upload service accepts (MB).
    'upload_max_mb' => (int) env('MARMARA_DESIGNS_UPLOAD_MAX_MB', 100),

    /*
     * A published post's images and videos that did not come from the
     * gallery are added to it (uploaded to Drive with the lead's token, so
     * only once "Drive'a yükleme izni" is given), into "<folder>/<YYYY-MM>".
     */
    'auto_add' => [
        'enabled' => (bool) env('MARMARA_DESIGNS_AUTO_ADD', true),
        'folder' => env('MARMARA_DESIGNS_AUTO_ADD_FOLDER', 'Paylaşılanlar'),
    ],

    // Changes pages one incremental sync reads before the next run continues.
    'max_change_pages' => (int) env('MARMARA_DESIGNS_MAX_CHANGE_PAGES', 20),

    // Mark every revision of a binary file keepForever=true (needs Editor on the folder).
    'keep_revisions' => (bool) env('MARMARA_DESIGNS_KEEP_REVISIONS', true),

    /*
     * A new design older than this is stored without the design.added
     * webhook (and the first full sync never notifies), so Echo is not
     * flooded by the folder's backlog.
     */
    'notify_max_age_hours' => (int) env('MARMARA_DESIGNS_NOTIFY_MAX_AGE_HOURS', 24),

    'thumbnails' => [
        // Local disk only (never S3): bounded LRU cache of the proxy's thumbnails.
        'path' => env('MARMARA_DESIGNS_THUMBNAIL_PATH', storage_path('app/marmara-designs/thumbnails')),
        'max_mb' => (int) env('MARMARA_DESIGNS_THUMBNAIL_CACHE_MB', 500),
        'sizes' => [400, 1600],
        'quality' => (int) env('MARMARA_DESIGNS_THUMBNAIL_QUALITY', 70),
        // Lifetime of the signed thumbnail URLs sent in webhook payloads.
        'signed_url_days' => (int) env('MARMARA_DESIGNS_SIGNED_URL_DAYS', 7),
        // Largest original the proxy downloads to make a thumbnail itself (revisions, missing Drive thumbnails).
        'source_max_mb' => (int) env('MARMARA_DESIGNS_THUMBNAIL_SOURCE_MAX_MB', 40),
    ],

];
