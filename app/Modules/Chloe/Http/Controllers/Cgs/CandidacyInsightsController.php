<?php

namespace App\Modules\Chloe\Http\Controllers\Cgs;

use App\Modules\Chloe\Support\CandidacyStatistics;
use App\Modules\Core\Http\Controllers\Controller;

/**
 * Candidacy Management > Insights (CGS roles): operational monitoring of the known candidacy expiry dates, in the look and with the mechanisms of
 * Workstation Insights. Every number comes from Support\CandidacyStatistics; the page embeds its flat rows and tallies them in the browser.
 */
class CandidacyInsightsController extends Controller
{
    public function index()
    {
        $payload = CandidacyStatistics::payload();

        return view('chloe::candidacy.cgs.insights', ['payload' => $payload, 'version' => CandidacyStatistics::version()]);
    }

    public function liveVersion()
    {
        return response()->json(['version' => CandidacyStatistics::version()]);
    }

    public function liveStats()
    {
        return response()->json(['version' => CandidacyStatistics::version(), 'data' => CandidacyStatistics::payload()]);
    }
}
