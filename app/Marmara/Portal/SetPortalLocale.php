<?php

declare(strict_types=1);

namespace App\Marmara\Portal;

use App\Http\Middleware\App\SetLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * TryPost's SetLocale renders every guest page in Locale::DEFAULT (English).
 * The provider binds this subclass in its place: upstream's middleware runs
 * first, unchanged, then a guest request is switched to APP_LOCALE. A signed-in
 * user keeps the language upstream picked (their own users.locale).
 */
class SetPortalLocale extends SetLocale
{
    public function __construct(private readonly PortalConfig $config) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return parent::handle($request, function (Request $request) use ($next): Response {
            if (! Auth::check() && ($locale = $this->config->defaultLocale()) !== null) {
                App::setLocale($locale->value);
                View::share('htmlDir', $locale->direction());
            }

            return $next($request);
        });
    }
}
