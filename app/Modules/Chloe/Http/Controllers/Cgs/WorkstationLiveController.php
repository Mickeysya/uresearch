<?php

namespace App\Modules\Chloe\Http\Controllers\Cgs;

use App\Modules\Chloe\Support\WorkstationLiveVersion;
use App\Modules\Chloe\Support\WorkstationStatistics;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The live-update endpoints (GET only, throttled, no-store via the
 * module's NoStoreResponse middleware). They return counts, versions and
 * the per-seat Insights fact rows the page already shows -- never a
 * name, student ID or email. Nothing here is cached: every response is
 * computed by the shared WorkstationStatistics functions.
 */
class WorkstationLiveController extends Controller
{
    public function version(): JsonResponse
    {
        return response()->json([
            'version' => WorkstationLiveVersion::cgs(),
            'server_time' => WorkstationLiveVersion::serverTime(),
        ]);
    }

    public function stats(): JsonResponse
    {
        // The version is read FIRST: if an action lands while the numbers
        // below are computed, the next poll sees a newer version and
        // refreshes again, rather than missing the change.
        $version = WorkstationLiveVersion::cgs();

        return response()->json([
            'version' => $version,
            'server_time' => WorkstationLiveVersion::serverTime(),
            'stats' => WorkstationStatistics::overall(),
            'rooms' => WorkstationStatistics::roomCards(),
        ]);
    }

    public function insights(): JsonResponse
    {
        $version = WorkstationLiveVersion::cgs();
        $rows = WorkstationStatistics::insightsFactRows();
        $meta = WorkstationStatistics::insightsMeta($rows);
        $meta['operations_url'] = route('workstation.cgs.operations');

        return response()->json([
            'version' => $version,
            'server_time' => WorkstationLiveVersion::serverTime(),
            'rows' => $rows,
            'meta' => $meta,
        ]);
    }

    /** The student's own version only -- see WorkstationLiveVersion::student(). */
    public function studentVersion(Request $request): JsonResponse
    {
        return response()->json([
            'version' => WorkstationLiveVersion::student($request->user()),
            'server_time' => WorkstationLiveVersion::serverTime(),
        ]);
    }
}
