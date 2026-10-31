<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A cheap "has anything changed?" token for the live-update pollers.
 *
 * The CGS version is one query of scalar MAX() sub-selects: the newest
 * workstation_histories id (EVERY seat/key action writes an append-only
 * history row -- CGS actions, student actions, the scheduled jobs and
 * demo seeding alike) plus the newest updated_at of the four tables the
 * pages read. It changes on any action that changes seat or key data and
 * is a handful of indexed lookups, never a scan.
 *
 * The student version covers ONLY that student's own requests, keys,
 * seat and notices, and is returned as a hash, so it can never leak
 * another user's data or even the shape of it.
 */
final class WorkstationLiveVersion
{
    public static function cgs(): string
    {
        $row = DB::selectOne('SELECT
            (SELECT MAX(id) FROM workstation_histories) AS h,
            (SELECT MAX(updated_at) FROM workstations) AS w,
            (SELECT MAX(updated_at) FROM workstation_requests) AS r,
            (SELECT MAX(updated_at) FROM locker_keys) AS k,
            (SELECT MAX(updated_at) FROM workstation_termination_notices) AS n,
            (SELECT COUNT(*) FROM workstation_histories) AS hc');

        return 'c'.substr(sha1(implode('|', [$row->h, $row->w, $row->r, $row->k, $row->n, $row->hc])), 0, 16);
    }

    public static function student(User $user): string
    {
        // Row id + status + updated_at of everything this student can see: a
        // status change inside the same second as the row's creation (a key
        // requested and collected back to back) still moves the hash, which
        // a bare MAX(updated_at) would miss. A handful of rows per student.
        $requests = DB::table('workstation_requests')->where('student_id', $user->id)->get(['id', 'workstation_id', 'status', 'updated_at']);
        $requestIds = $requests->pluck('id');

        return 's'.substr(sha1(json_encode([
            $requests,
            DB::table('locker_keys')->where('student_id', $user->id)->get(['id', 'status', 'updated_at']),
            DB::table('workstations')->whereIn('id', $requests->pluck('workstation_id'))->get(['id', 'status', 'updated_at']),
            DB::table('workstation_termination_notices')->whereIn('workstation_request_id', $requestIds)->get(['id', 'status', 'updated_at']),
        ])), 0, 16);
    }

    /**
     * Called first thing by a page's controller: records the version the
     * page's data is about to be read at. If an action lands while the
     * page is being built, the page still carries the OLDER version, so
     * its first poll sees the change instead of silently missing it.
     */
    public static function begin(?User $student = null): void
    {
        request()->attributes->set('wl_version', $student ? self::student($student) : self::cgs());
    }

    public static function current(?User $student = null): string
    {
        return request()->attributes->get('wl_version') ?? ($student ? self::student($student) : self::cgs());
    }

    /** Server clock in Malaysia time, for the "updated hh:mm:ss" indicator. */
    public static function serverTime(): string
    {
        return now('Asia/Kuala_Lumpur')->format('H:i:s');
    }
}
