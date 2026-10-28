<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Nureen\Support\ProcessingTimeAnalytics;

/**
 * Processing-time visibility for CGS Staff across GA Extension, Supervision
 * and Certification -- see Support\ProcessingTimeAnalytics for how both
 * figures are computed.
 */
class AnalyticsController extends Controller
{
    public function index(ProcessingTimeAnalytics $analytics)
    {
        return view('nureen::analytics.index', [
            'averages' => $analytics->averageDaysByStageAndModule(),
            'overdue' => $analytics->overdueApplications(),
        ]);
    }
}
