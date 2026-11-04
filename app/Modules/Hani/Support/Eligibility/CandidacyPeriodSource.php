<?php

namespace App\Modules\Hani\Support\Eligibility;

use App\Modules\Core\Models\User;
use App\Modules\Hani\Support\VivaChecklist;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Within candidacy period", checked from the candidature start date in
 * Norhanis's RPD masterlist (candidacies.candidature_start_date and
 * programme_type, one row per student).
 *
 * A guarded, read-only lookup: there is no Core contract for it yet (one is
 * proposed in SHARED_CHANGES.md), so this reads the table directly and never
 * imports her classes. If the table or a column is missing it says nothing
 * rather than failing -- the item simply stays self-declared.
 *
 * Limits in config('hani.eligibility.candidacy_months'), provisional.
 */
class CandidacyPeriodSource implements EligibilitySource
{
    protected const TABLE = 'candidacies';

    protected const COLUMNS = ['student_id', 'candidature_start_date', 'programme_type'];

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

        if (! $row || ! $row->candidature_start_date) {
            return EligibilityResult::selfDeclared('No candidature record was found for you, so this stays self-declared.');
        }

        [$level, $partTime] = $this->programme($row->programme_type, $student);

        if ($level === null) {
            return EligibilityResult::selfDeclared('Your candidature record does not say which programme you are on.');
        }

        $months = (int) config('hani.eligibility.candidacy_months.'.$level.'.'.($partTime ? 'part_time' : 'full_time'));
        $start = Carbon::parse($row->candidature_start_date)->startOfDay();
        $ends = $start->copy()->addMonthsNoOverflow($months);
        $mode = ($level === VivaChecklist::PHD ? 'PhD' : 'MSc').' '.($partTime ? 'part-time' : 'full-time');

        return now()->startOfDay()->lte($ends)
            ? new EligibilityResult(EligibilityResult::VERIFIED,
                "Candidature started {$start->format('j M Y')}; the {$months}-month limit for {$mode} ends {$ends->format('j M Y')}.")
            : new EligibilityResult(EligibilityResult::NOT_MET,
                "Candidature started {$start->format('j M Y')}; the {$months}-month limit for {$mode} ended {$ends->format('j M Y')}. Contact CGS about an extension.");
    }

    /**
     * msc_ft | msc_pt | phd_ft | phd_pt from the candidacy row; failing that,
     * the student's own programme field.
     *
     * @return array{0: ?string, 1: bool}
     */
    protected function programme(?string $type, User $student): array
    {
        return match (strtolower((string) $type)) {
            'msc_ft' => [VivaChecklist::MSC, false],
            'msc_pt' => [VivaChecklist::MSC, true],
            'phd_ft' => [VivaChecklist::PHD, false],
            'phd_pt' => [VivaChecklist::PHD, true],
            default => [VivaChecklist::levelFor($student), VivaChecklist::isPartTime($student)],
        };
    }
}
