<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Marmara AI fork: club portal (default language and guest branding)
|--------------------------------------------------------------------------
|
| Owned by the App\Marmara\Portal module (see app/Marmara/README.md), not by
| upstream TryPost.
|
| Default language: guests, and sign-ups that never picked a language, get
| APP_LOCALE (or MARMARA_DEFAULT_LOCALE) instead of TryPost's built-in English.
| APP_LOCALE=en (or any value TryPost does not support) is upstream behaviour.
| A user's own language (users.locale) always wins once they are signed in.
|
| Branding: with MARMARA_BRANDING=false the guest screens (login, register,
| forgot / reset password, verify email) look exactly like upstream.
|
*/

return [

    /*
     * Copied from APP_LOCALE when the config loads. It must not be read from
     * config('app.locale') at request time: App::setLocale() (called by
     * upstream SetLocale on every web request) overwrites that key.
     * MARMARA_DEFAULT_LOCALE overrides it if APP_LOCALE should stay en.
     */
    'default_locale' => env('MARMARA_DEFAULT_LOCALE', env('APP_LOCALE', 'en')),

    'branding' => [

        'enabled' => (bool) env('MARMARA_BRANDING', false),

        'name' => env('MARMARA_BRAND_NAME', 'Marmara Yapay Zeka Kulübü'),

        'tagline' => env('MARMARA_BRAND_TAGLINE', 'Sosyal medya paneli'),

        /*
         * The logo: a URL, or a path under public/. Left empty, the first of
         * public/marmara/logo.svg, logo.png, logo.webp that exists is used,
         * and without one the club name is shown as a text wordmark.
         * MARMARA_BRAND_LOGO_DARK (or public/marmara/logo-dark.*) is the
         * variant for dark mode; without it the light logo is used there too.
         */
        'logo' => env('MARMARA_BRAND_LOGO'),

        'logo_dark' => env('MARMARA_BRAND_LOGO_DARK'),

        /*
         * Optional accent (#rgb or #rrggbb) for buttons and links on the guest
         * screens only. Empty keeps TryPost's own colours.
         */
        'accent' => env('MARMARA_BRAND_ACCENT'),

        /*
         * Optional link to this fork's source code (AGPL-3.0 section 13 asks a
         * modified network service to offer its source to its users).
         */
        'source_url' => env('MARMARA_SOURCE_URL'),

    ],

];
