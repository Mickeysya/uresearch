<?php

namespace App\Modules\Chloe\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every live page and JSON endpoint of the Workstation module is sent
 * `Cache-Control: no-store, max-age=0` (plus `Pragma: no-cache`).
 *
 * Why not Laravel's default `no-cache, private`: a browser still keeps
 * such a page and re-shows the stored copy on Back/Forward without asking
 * the server, so the landing page showed "Key Pending Collection 23" after
 * the key had been collected (`no-cache` only forces revalidation on a
 * normal load; history navigation skips it). `no-store` is the one
 * directive that makes a history step re-request the page.
 *
 * Applied only to this module's own routes (routes.php), never globally.
 */
class NoStoreResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->remove('ETag');
        $response->headers->remove('Last-Modified');

        return $response;
    }
}
