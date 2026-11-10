<?php

namespace App\Modules\Haziq\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GaAllowancePaymentLog;
use Illuminate\Support\Carbon;

/**
 * GA's own allowance cycle -- deliberately separate from (never merged
 * with) GRA's, confirmed explicitly 2026-11-10: "ga ni letak actions
 * lain. jangan sekali kan dengan allowance gra satu excel." Simpler
 * than GRA's in one respect -- no Stage Gates, no termination, no
 * partial-month judgement calls -- but it accumulates the same way
 * GRA's own does, confirmed explicitly 2026-11-10, reversing an
 * earlier "resets every month" version from the same day: "ga pun sama
 * ah, kalau ada accumulated amount on service end month, month after
 * payment accumulated amount tu."
 *
 *   "bulan pertama start service takkan dapat allowance, next month
 *   dapat allowance untuk bulan sebelumnya... bulan berikutnya
 *   sampailah die tak active."
 *
 * This class only ever writes ONE FLAT ROW per calendar month, dated
 * by the SERVICE month it is paying for, never the month it is
 * displayed in -- the accumulating running total itself is computed
 * separately, at display/confirm time, by GaAllowanceLedger walking
 * these same flat rows forward and resetting at whichever one is
 * confirmed. Keeping generation flat and pushing the walk to the
 * ledger (rather than writing an already-accumulated figure into each
 * row) is what lets a late confirmation still correctly roll up every
 * month it was actually covering, the same shape as GRA's own
 * AllowancePaymentLog/AllowanceLedger split. The "no allowance in the
 * very first month" and "one month's allowance pays out the following
 * month" rules both fall out of the same payroll-lag convention GRA
 * already uses (GaAllowanceController's own serviceMonthFor()).
 *
 * service_end_date is always read live off GaApplicationDetail -- an
 * approved GA Extension (Nureen's own module) updates that column in
 * place once approved, confirmed_by its own docblock, so there is no
 * original-vs-extension chain to walk here the way GRA's extension (a
 * separate Application row) needs: "sevrice end date kalau tak ade
 * extention ikut je dari application asal, kalau ade ikut newly
 * requested ah (mesti yang approve je amik kira)" is already true by
 * construction, for free.
 *
 * "end of service date next monthnya still dapat allowance" (one
 * trailing payment) is also free: a row is generated for the service
 * end date's own month same as any other, and the payroll lag alone
 * makes that row first appear the month after -- nothing special-cased
 * for it. Generation simply stops at that month; nothing "inactive"
 * ever gets a further row.
 */
class GaAllowanceEngine
{
    /**
     * Idempotent -- safe to call on every index()/export() request
     * rather than needing its own scheduled job, since every row this
     * writes is a pure function of (today, service_start_date,
     * service_end_date), none of which change retroactively the way a
     * Stage Gate deadline or grace period can.
     */
    public function ensureMonthlyLogs(Application $application): void
    {
        $detail = GaApplicationDetail::where('application_id', $application->id)->first();

        if (! $detail?->service_start_date) {
            return;
        }

        $thisMonth = Carbon::today()->startOfMonth();
        $start = $detail->service_start_date->copy()->startOfMonth();
        $lastMonth = $detail->service_end_date
            ? Carbon::parse($detail->service_end_date)->startOfMonth()->min($thisMonth)
            : $thisMonth;

        if ($start->gt($lastMonth)) {
            return;
        }

        $existingMonths = GaAllowancePaymentLog::where('application_id', $application->id)
            ->pluck('payment_month')
            ->map(fn ($m) => Carbon::parse($m)->format('Y-m'))
            ->flip();

        $amount = (float) $detail->proposed_allowance;
        $cursor = $start->copy();

        while ($cursor->lte($lastMonth)) {
            if (! isset($existingMonths[$cursor->format('Y-m')])) {
                GaAllowancePaymentLog::create([
                    'application_id' => $application->id,
                    'student_id' => $application->student_id,
                    'payment_month' => $cursor->copy(),
                    'amount' => $amount,
                ]);
            }

            $cursor->addMonthNoOverflow();
        }
    }

    /** The service month being paid out when viewing $displayMonth -- same payroll lag as GRA's own serviceMonthFor(). */
    public function serviceMonthFor(Carbon $displayMonth): Carbon
    {
        return $displayMonth->copy()->subMonth();
    }
}
