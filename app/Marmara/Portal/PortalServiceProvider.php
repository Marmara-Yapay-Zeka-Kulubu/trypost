<?php

declare(strict_types=1);

namespace App\Marmara\Portal;

use App\Http\Middleware\App\SetLocale;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

/**
 * Marmara AI fork module: the club portal's default language and the branded
 * guest (auth) screens. See app/Marmara/README.md.
 *
 * Laravel resolves route middleware from the container, so binding SetLocale
 * here swaps in SetPortalLocale without editing bootstrap/app.php. The
 * frontend reads the shared `marmara` prop; with APP_LOCALE=en and
 * MARMARA_BRANDING=false it is all nulls and TryPost behaves as upstream.
 */
class PortalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PortalConfig::class);

        $this->app->bind(SetLocale::class, SetPortalLocale::class);
    }

    public function boot(): void
    {
        Inertia::share('marmara', function (): array {
            $config = $this->app->make(PortalConfig::class);

            return [
                // Guests' default language, preferred over the browser's when
                // a sign-up never picked one (resources/js/marmara/portal.ts).
                'defaultLocale' => $config->defaultLocale()?->value,
                'branding' => $config->branding(),
            ];
        });
    }
}
