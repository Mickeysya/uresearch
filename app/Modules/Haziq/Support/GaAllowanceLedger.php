<?php

namespace App\Modules\Haziq\Support;

use App\Modules\Haziq\Models\GaAllowancePaymentLog;
use Illuminate\Support\Carbon;

/**
 * GA's own accumulating running total -- confirmed explicitly
 * 2026-11-10, reversing the earlier "resets every month" design: "ga
 * pun sama ah, kalau ada accumulated amount on service end month, month
 * after payment accumulated amount tu." A month nobody confirms adds
 * onto the next month's total, the same idea as GRA's own
 * AllowanceLedger, kept as a separate, much simpler class since GA has
 * none of GRA's Stage-1/termination machinery to account for: here,
 * every row is either confirmed or it accumulates, full stop.
 */
class GaAllowanceLedger
{
    /** The running accumulating total through the end of $month, inclusive. 0.0 once confirmed-paid. */
    public static function accumulatedTotalThrough(int $applicationId, Carbon $month): float
    {
        $logs = GaAllowancePaymentLog::where('application_id', $applicationId)
            ->where('payment_month', '<=', $month)
            ->orderBy('payment_month')
            ->get(['payment_month', 'amount', 'confirmed_at']);

        $runningSum = 0.0;

        foreach ($logs as $log) {
            $runningSum += (float) $log->amount;

            if ($log->confirmed_at !== null) {
                $runningSum = 0.0;
            }
        }

        return $runningSum;
    }

    /**
     * Confirming has to happen in order, oldest unconfirmed month
     * first -- same reason GRA locks a second consecutive withheld
     * month: a later month's own "amount" already reads as the running
     * total including everything before it, so confirming it without
     * confirming what came first would hide which specific months the
     * proof attached to it was actually for.
     */
    public static function isOldestUnconfirmed(GaAllowancePaymentLog $log): bool
    {
        if ($log->isConfirmed()) {
            return false;
        }

        return ! GaAllowancePaymentLog::where('application_id', $log->application_id)
            ->where('payment_month', '<', $log->payment_month)
            ->whereNull('confirmed_at')
            ->exists();
    }
}
