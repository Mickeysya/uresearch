<?php

namespace App\Modules\Hani\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's `signed` check for the examiner's re-viva link, with the two
 * failures told apart. `signed` answers "Invalid signature" for an expired
 * link too, which sent an examiner (and us) looking for a broken signature.
 *
 *   wrong or tampered signature  403, as before
 *   correct but expired          410, a page giving the date it expired and
 *                                the CGS contacts to ask for a new link
 *
 * The signature is checked first, so the expired page is only ever shown
 * for a URL this application really issued.
 */
class ValidPanelLink
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(URL::hasCorrectSignature($request), 403, 'This link is not valid. Please use the link in your letter email, or contact the Centre for Graduate Studies.');

        if (! URL::signatureHasNotExpired($request)) {
            return response()->view('hani::re_viva.panel_link_expired', [
                'expired' => Carbon::createFromTimestamp((int) $request->query('expires'), config('app.timezone')),
                'contacts' => (array) config('hani.reviva.letter.return_contacts'),
            ], 410);
        }

        return $next($request);
    }
}
