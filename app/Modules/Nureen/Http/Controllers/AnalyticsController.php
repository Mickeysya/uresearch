<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Nureen\Support\ProcessingTimeAnalytics;

/**
 * Client Charter compliance for CGS Staff across GA Extension, Supervision
 * and Certification -- see Support\ProcessingTimeAnalytics for how both
 * figures are computed.
 */
class AnalyticsController extends Controller
{
    public function index(ProcessingTimeAnalytics $analytics)
    {
        return view('nureen::analytics.index', [
            'compliance' => $analytics->complianceByStageAndModule(),
            'overdue' => $analytics->overdueApplications(),
        ]);
    }
}
