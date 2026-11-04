<?php

namespace App\Modules\Hani\Support\Eligibility;

use App\Modules\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Within candidacy period", checked against Chloe's Study Candidacy record
 * (study_candidacies: one row per student, with the candidacy expiry date
 * that already includes any extension granted on appeal, and the status).
 *
 * It used to read Norhanis's RPD masterlist (candidacies), which develop
 * removed with RPD Candidacy (2026_10_30_000100_remove_rpd_candidacy).
 *
 * A guarded, read-only lookup: there is no Core contract for it yet (one is
 * proposed in SHARED_CHANGES.md), so this reads the table directly and never
 * imports her classes. If the table or a column is missing it says nothing
 * rather than failing: the item simply stays self-declared.
 */
class CandidacyPeriodSource implements EligibilitySource
{
    protected const TABLE = 'study_candidacies';

    protected const COLUMNS = ['student_id', 'programme_start_date', 'candidacy_expiry_date', 'status'];

    /** Study Candidacy's status for a dismissed candidature. */
    protected const DISMISSED = 'dismissed';

    public function column(): string
    {
        return 'within_candidacy_period';
    }

    public function check(User $student): EligibilityResult
    {
        try {
            if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumns(self::TABLE, self::COLUMNS)) {
                return EligibilityResult::selfDeclared('No candidature record is available to the viva module.');
            }

            $row = DB::table(self::TABLE)
                ->where('student_id', $student->id)
                ->first(self::COLUMNS);
        } catch (\Throwable $e) {
            report($e);

            return new EligibilityResult(EligibilityResult::FAILED,
                'The candidacy period could not be checked automatically just now. Try again later, or contact CGS if this continues.');
        }

        if (! $row || ! $row->candidacy_expiry_date) {
            return EligibilityResult::selfDeclared('No candidature record was found for you, so this stays self-declared.');
        }

        $ends = Carbon::parse($row->candidacy_expiry_date)->startOfDay();
        $started = $row->programme_start_date ? 'Candidature started '.Carbon::parse($row->programme_start_date)->format('j M Y').'; ' : '';

        if ($row->status === self::DISMISSED) {
            return new EligibilityResult(EligibilityResult::NOT_MET,
                "{$started}your candidature is recorded as dismissed. Contact CGS.");
        }

        return now()->startOfDay()->lte($ends)
            ? new EligibilityResult(EligibilityResult::VERIFIED,
                "{$started}your candidacy period ends {$ends->format('j M Y')}, including any extension granted.")
            : new EligibilityResult(EligibilityResult::NOT_MET,
                "{$started}your candidacy period ended {$ends->format('j M Y')}. Apply for an extension through Study Candidacy, or contact CGS.");
    }
}
