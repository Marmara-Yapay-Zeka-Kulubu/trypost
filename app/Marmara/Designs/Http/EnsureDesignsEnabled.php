<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Http;

use App\Marmara\Designs\DesignsConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The module's routes are always registered (so `route:cache` keeps them)
 * and answer 404 while MARMARA_DESIGNS_ENABLED is off.
 */
class EnsureDesignsEnabled
{
    public function __construct(private readonly DesignsConfig $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->config->enabled(), 404);

        return $next($request);
    }
}
