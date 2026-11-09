<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Models\WorkstationTerminationNotice;
use App\Modules\Chloe\Services\WorkstationAllocator;

/**
 * PART 3 #5's mapping, built from what the codebase actually tracks today.
 * Real accounts only (StudyCandidacy belongs to a real `users` row; a demo
 * student has no StudyCandidacy at all, so one is never found for them
 * here) -- the daily job additionally skips demo students itself (PART 3
 * #6c), this class just never has anything to find for one in the first
 * place.
 *
 * | Reason                                    | Data source                                                              | Automatic |
 * |--------------------------------------------|---------------------------------------------------------------------------|-----------|
 * | Dismissal                                   | StudyCandidacy.status = 'dismissed'                                      | YES       |
 * | Graduated                                   | StudyCandidacy.status = 'completed'                                      | YES       |
 * | Loan period ended (candidacy expiry)        | StudyCandidacy.candidacy_expiry_date passed, status still active, no open appeal | YES |
 * | Deferment                                   | no distinct status exists -- only 'inactive', which also covers Withdrawn/De-registered/absence and cannot be told apart from them | NO (CGS manual) |
 * | Withdrawn                                   | same ambiguous 'inactive' status                                         | NO (CGS manual) |
 * | De-registered                               | no data tracked anywhere                                                 | NO (CGS manual) |
 * | Under disciplinary action                   | no data tracked anywhere                                                 | NO (CGS manual) |
 * | Absent without prior approval                | no data tracked anywhere                                                 | NO (CGS manual) |
 * | Change programme to Coursework              | `programme` is a current-value snapshot, not a change event -- no "it just changed" signal exists | NO (CGS manual) |
 * | Change programme mode to Part-Time          | same -- no change-event tracking                                         | NO (CGS manual) |
 * | Other reason deemed appropriate by the University | inherently manual by definition                                    | NO (CGS manual) |
 *
 * Only the three YES rows are implemented below. The rest stay in
 * WorkstationAllocator::REASONS_TERMINATION for CGS's own Release dropdown
 * and nowhere else -- no fake data, no new columns on StudyCandidacy, to
 * manufacture a signal that does not exist.
 *
 * Never instantiated -- see findForOccupiedSeats(), the one entry point.
 */
final class WorkstationTerminationTriggers
{
    /**
     * @return array<int, array{request: WorkstationRequest, reason: string}>
     */
    public static function findForOccupiedSeats(): array
    {
        $triggers = [];

        WorkstationRequest::where('status', WorkstationRequest::STATUS_CONFIRMED)
            ->whereNotNull('student_id')
            ->get()
            ->each(function (WorkstationRequest $request) use (&$triggers) {
                $hasActiveNotice = WorkstationTerminationNotice::where('workstation_request_id', $request->id)
                    ->where('status', WorkstationTerminationNotice::STATUS_ACTIVE)
                    ->exists();

                if ($hasActiveNotice) {
                    return;
                }

                $candidacy = StudyCandidacy::where('student_id', $request->student_id)->first();

                if (! $candidacy) {
                    return;
                }

                $reason = match (true) {
                    $candidacy->status === StudyCandidacy::STATUS_DISMISSED => WorkstationAllocator::REASON_TERMINATION_DISMISSAL,
                    $candidacy->status === StudyCandidacy::STATUS_COMPLETED => WorkstationAllocator::REASON_TERMINATION_GRADUATED,
                    $candidacy->status === StudyCandidacy::STATUS_ACTIVE
                        && ExpiryRule::hasPassed($candidacy->candidacy_expiry_date)
                        && ! $candidacy->hasOpenAppeal() => WorkstationAllocator::REASON_TERMINATION_EXPIRY,
                    default => null,
                };

                if ($reason !== null) {
                    $triggers[] = ['request' => $request, 'reason' => $reason];
                }
            });

        return $triggers;
    }
}
