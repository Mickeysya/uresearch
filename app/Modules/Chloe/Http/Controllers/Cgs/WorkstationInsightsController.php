<?php

namespace App\Modules\Chloe\Http\Controllers\Cgs;

use App\Modules\Chloe\Support\WorkstationStatistics;
use App\Modules\Core\Http\Controllers\Controller;

/**
 * Round 5 PART B/D: a CGS-only read-only dashboard, one route, one
 * method. Unlike Round 4's version (nine pre-computed scopes), this page
 * embeds ONE flat fact table -- one row per seat, no PII -- plus a small
 * meta object, and does every aggregation/cross-filter client-side (see
 * public/js/chloe-insights-aggregate.js and
 * App\Modules\Chloe\Support\InsightsAggregator, its PHP reference). The
 * unfiltered page can never disagree with the CGS stats bar or a chat
 * answer, because WorkstationStatistics::insightsFactRows() is built
 * from the exact same data those read -- see
 * tests/Feature/WorkstationInsightsTest.php for the cross-check.
 */
class WorkstationInsightsController extends Controller
{
    public function index()
    {
        $rows = WorkstationStatistics::insightsFactRows();
        $meta = WorkstationStatistics::insightsMeta($rows);

        // Route-dependent, so built here rather than inside WorkstationStatistics (which has no business knowing route names).
        $meta['operations_url'] = route('workstation.cgs.operations');

        return view('chloe::workstation.cgs.insights', [
            'rows' => $rows,
            'meta' => $meta,
        ]);
    }
}
