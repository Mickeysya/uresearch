<?php

namespace App\Modules\Chloe\Services;

use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Core\Contracts\SuppliesCandidacyStatus;
use App\Modules\Core\Models\User;

/**
 * Chloe's study candidacy, as Core's student dashboard sees it.
 *
 * Same seam as Nureen's AttendanceProvider for SuppliesAttendance: the only
 * place that knows both `study_candidacies` and the shape Core's dashboard
 * card wants. Bound from this module's ModuleProvider; if this module were
 * removed, nothing would bind SuppliesCandidacyStatus and the card would
 * hold its empty state rather than Core naming StudyCandidacy directly.
 */
class CandidacyStatusProvider implements SuppliesCandidacyStatus
{
    public function candidacyStatusFor(User $student): ?array
    {
        $candidacy = StudyCandidacy::where('student_id', $student->id)->first();

        if (! $candidacy) {
            return null;
        }

        $days = $candidacy->daysUntilExpiry();

        $tone = match (true) {
            $candidacy->status !== StudyCandidacy::STATUS_ACTIVE => 'info',
            $days < 0 => 'critical',
            $days <= 90 => 'warn',
            default => 'good',
        };

        return [
            'daysRemaining' => $days,
            // Whole months completed since the programme started, plus the
            // one in progress -- "25" means the student is partway through
            // their 25th month, the same whole-months convention GA
            // Extension's stage gate rules use.
            'monthsIn' => $candidacy->programme_start_date
                ? (int) $candidacy->programme_start_date->diffInMonths(now()) + 1
                : null,
            'startedAt' => $candidacy->programme_start_date,
            'expiresAt' => $candidacy->candidacy_expiry_date,
            'extensionUsedMonths' => (int) $candidacy->cumulative_extension_months,
            'extensionMaxMonths' => StudyCandidacy::MAX_APPEAL_MONTHS,
            'statusLabel' => StudyCandidacy::statuses()[$candidacy->status] ?? ucfirst($candidacy->status),
            'tone' => $tone,
            'url' => route('candidacy.status'),
        ];
    }
}
