<?php

namespace App\Modules\Haziq\Support;

use App\Modules\Haziq\Models\AllowancePaymentConfirmation;
use App\Modules\Haziq\Models\AllowancePaymentLog;
use Illuminate\Support\Carbon;

/**
 * The accumulating-and-unconfirmed running total as of a given month —
 * shared by AllowanceController::rowFor() (the on-screen walk, which
 * re-derives the same sum inline for every other row it displays) and
 * StageGateEngine (reinstatement handling), so the two can never drift
 * out of sync with what the table actually shows. A standalone sum, not
 * the full display walk — no forfeited/review_required/determined
 * labelling, no confirmable/reviewable flags, just the number.
 *
 * Covers 'eligible' as well as 'withheld'/'backdated', confirmed
 * explicitly 2026-11-05: every month now needs an explicit CGS
 * confirmation regardless of whether Stage 1 is actually overdue (see
 * rowFor()'s own merged branch) — an on-time month CGS never confirmed
 * is real unconfirmed money sitting there exactly like an overdue one,
 * and has to survive a termination/resumption the same way. Missed on
 * the first pass of that change: this class still only recognised
 * 'withheld'/'backdated', so unconfirmed money from ordinary eligible
 * months was silently discarded — never restored on appeal — the
 * moment a termination hit.
 */
class AllowanceLedger
{
    /** Raw statuses that accumulate into the running total — kept in one place so rowFor() and this class can never drift on what counts. */
    public const ACCUMULATING_STATUSES = ['eligible', 'withheld', 'backdated'];

    /** The running accumulating total through the end of $month, inclusive. 0.0 once confirmed-paid. */
    public static function withheldTotalThrough(int $applicationId, Carbon $month): float
    {
        $history = AllowancePaymentLog::where('application_id', $applicationId)
            ->where('payment_month', '<=', $month)
            ->orderBy('payment_month')
            ->get(['payment_month', 'status', 'amount']);

        $confirmedMonths = AllowancePaymentConfirmation::where('application_id', $applicationId)
            ->pluck('confirmed_month')
            ->map(fn ($m) => Carbon::parse($m)->format('Y-m'))
            ->flip();

        $runningSum = 0.0;

        foreach ($history as $row) {
            if (! in_array($row->status, self::ACCUMULATING_STATUSES, true)) {
                $runningSum = 0.0;

                continue;
            }

            $runningSum += (float) $row->amount;

            if (isset($confirmedMonths[Carbon::parse($row->payment_month)->format('Y-m')])) {
                $runningSum = 0.0;
            }
        }

        return $runningSum;
    }

    /**
     * The running total at the exact month immediately before a
     * termination gap started — 0.0 if nothing was mid-accumulation
     * right then (either already resolved and confirmed-paid, or never
     * even due yet). Only looks at the run *immediately* adjacent to the
     * gap — an older, already-settled accumulating period from well
     * before an unrelated earlier gap doesn't count.
     */
    public static function priorWithheldTotal(int $applicationId, Carbon $beforeMonth): float
    {
        $lastBeforeGap = AllowancePaymentLog::where('application_id', $applicationId)
            ->where('payment_month', '<', $beforeMonth)
            ->whereIn('status', self::ACCUMULATING_STATUSES)
            ->orderByDesc('payment_month')
            ->first();

        if (! $lastBeforeGap) {
            return 0.0;
        }

        $between = AllowancePaymentLog::where('application_id', $applicationId)
            ->where('payment_month', '>', $lastBeforeGap->payment_month)
            ->where('payment_month', '<', $beforeMonth)
            ->get(['status']);

        if ($between->contains(fn ($r) => ! in_array($r->status, ['forfeited', 'review_required', 'determined'], true))) {
            return 0.0;
        }

        return self::withheldTotalThrough($applicationId, Carbon::parse($lastBeforeGap->payment_month));
    }
}
