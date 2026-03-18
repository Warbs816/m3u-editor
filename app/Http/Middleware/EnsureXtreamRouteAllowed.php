<?php

namespace App\Http\Middleware;

use App\Settings\GeneralSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureXtreamRouteAllowed
{
    public function __construct(public GeneralSettings $settings) {}

    /**
     * Handle an incoming request.
     *
     * Only block when ALL three conditions are true:
     *  1. Xtream dedicated port feature is enabled (ENV)
     *  2. "Restrict to dedicated port" setting is on (DB)
     *  3. Request did NOT arrive via the Xtream nginx proxy (no X-Xtream-Request header)
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('xtream.enabled')) {
            return $next($request);
        }

        if (! $this->settings->xtream_restrict_to_dedicated_port) {
            return $next($request);
        }

        if ($request->header('X-Xtream-Request') === 'true') {
            return $next($request);
        }

        abort(404);
    }
}
