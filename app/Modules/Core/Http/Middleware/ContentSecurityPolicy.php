<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Support\Csp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the Content-Security-Policy header on every HTML response.
 *
 * NO HOST ALLOW-LIST. script-src names no origin at all: Chart.js and its
 * datalabels plugin are served from public/js rather than a CDN, so the only
 * script this page will run is its own, or an inline block carrying this
 * request's nonce. That is the "strict CSP" form -- an allow-listed CDN is a
 * standing permission to run whatever that CDN serves, and jsdelivr hosts
 * every package on npm.
 *
 * The portal had no CSP at all, which meant that if a single `{!! !!}` or an
 * unescaped attribute ever slipped into a Blade file, an injected <script>
 * would simply run. Blade's escaping is the first defence; this is the one
 * that holds when the first is bypassed.
 *
 * NO 'unsafe-eval'. Nothing here needs it: neither Chart.js 4.4.1 nor
 * chartjs-plugin-datalabels 2.2.0 calls eval() or new Function() -- both were
 * checked, not assumed -- and no code in this repo does either. If a future
 * library appears to need it, replace the library rather than open the
 * directive; 'unsafe-eval' turns every string the page touches into a
 * potential script.
 *
 * NO 'unsafe-inline' for scripts either. Every inline <script> in the portal
 * carries @cspNonce, so it is allow-listed individually and an injected one
 * is not. Adding a new inline block without the nonce means it silently does
 * not run -- that is the point, and the browser console says so plainly.
 *
 * style-src DOES allow 'unsafe-inline', deliberately. 44 inline `style="..."`
 * attributes carry real values -- the gauge's --arc angle, the donut's
 * stroke-dashoffset, per-card animation delays -- and CSP has no nonce
 * mechanism for style *attributes*, only for <style> elements. Inline style
 * injection cannot execute script, so this is a far smaller exposure than the
 * script directives, and closing it would mean rewriting every chart.
 */
class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        Csp::reset();

        $response = $next($request);

        // A download streamed from DocumentController is not a document the
        // browser parses, and attaching a policy to it only risks surprising
        // a future content type.
        if (! $this->isHtml($response)) {
            return $response;
        }

        $response->headers->set('Content-Security-Policy', $this->policy());

        // Cheap companions, all closing holes a CSP does not.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }

    protected function policy(): string
    {
        $nonce = "'nonce-".Csp::nonce()."'";

        return implode('; ', [
            "default-src 'self'",
            // No origin named: every script is either this app's own file or
            // an inline block carrying the nonce.
            "script-src 'self' {$nonce}",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            // The portal is server-rendered; nothing fetches cross-origin.
            // This is also what refused DevTools' request for Chart.js's
            // source map back when the library came from a CDN — see
            // public/js/chart.umd.min.js for why that map is not shipped.
            "connect-src 'self'",
            // No Flash, no applets, nothing to embed.
            "object-src 'none'",
            // Stops an injected <base> redirecting every relative URL.
            "base-uri 'self'",
            // A form on this page may only post back to this origin.
            "form-action 'self'",
            // Nothing here should ever be framed -- clickjacking on an
            // approval button would be a real attack.
            "frame-ancestors 'none'",
        ]);
    }

    protected function isHtml(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
