<?php

namespace App\Modules\Chloe\Services;

use App\Modules\Chloe\Models\CandidacyDismissal;
use App\Modules\Chloe\Models\StudyCandidacy;

/**
 * Shared by the daily scheduled command and CGS's on-demand "Refresh List"
 * button, so the eligibility rule lives in exactly one place.
 *
 * Three reasons, checked in this order because they can genuinely overlap
 * for the same student (a rejection and a past expiry date are both often
 * true at once) — each candidacy gets at most one row, the most specific
 * reason wins.
 */
class DismissalGenerator
{
    public function generate(): int
    {
        $created = 0;

        StudyCandidacy::where('status', StudyCandidacy::STATUS_ACTIVE)
            ->chunkById(100, function ($candidacies) use (&$created) {
                foreach ($candidacies as $candidacy) {
                    if ($this->listOne($candidacy)) {
                        $created++;
                    }
                }
            });

        return $created;
    }

    protected function listOne(StudyCandidacy $candidacy): bool
    {
        // An appeal in flight means the student is actively contesting —
        // not a dismissal candidate until it is resolved one way or another.
        if ($candidacy->hasOpenAppeal()) {
            return false;
        }

        $reason = match (true) {
            $candidacy->last_rejection_at !== null => CandidacyDismissal::REASON_APPEAL_REJECTED,
            $candidacy->remainingAppealMonths() <= 0 && $candidacy->candidacy_expiry_date->isPast() => CandidacyDismissal::REASON_EXTENSION_EXHAUSTED,
            $candidacy->candidacy_expiry_date->isPast() => CandidacyDismissal::REASON_EXPIRED_NO_APPEAL,
            default => null,
        };

        if ($reason === null) {
            return false;
        }

        $alreadyListed = CandidacyDismissal::where('study_candidacy_id', $candidacy->id)
            ->whereIn('status', [CandidacyDismissal::STATUS_PENDING_REVIEW, CandidacyDismissal::STATUS_CONFIRMED])
            ->exists();

        if ($alreadyListed) {
            return false;
        }

        CandidacyDismissal::create([
            'study_candidacy_id' => $candidacy->id,
            'student_id' => $candidacy->student_id,
            'reason' => $reason,
            'generated_at' => now(),
            'status' => CandidacyDismissal::STATUS_PENDING_REVIEW,
        ]);

        return true;
    }
}
