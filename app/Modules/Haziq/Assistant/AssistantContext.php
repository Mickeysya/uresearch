<?php

namespace App\Modules\Haziq\Assistant;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Haziq\Models\AllowancePaymentLog;
use App\Modules\Haziq\Models\ApplicationCorrectionRequest;
use App\Modules\Haziq\Models\CandidatureAppeal;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\StageGate;
use App\Modules\Haziq\Models\TaHourLog;
use App\Modules\Haziq\Support\AllowanceLedger;
use Illuminate\Support\Carbon;

/**
 * Builds the plain-text "live records" block GeminiClient::ask() sends
 * alongside a student's question -- the scope doc's own words: "queries
 * the student's live records... so answers reflect current state."
 * Everything here is scoped to the ONE student asking (never another
 * student's row) and reads directly off the same tables the student's
 * own pages already show them, nothing more sensitive than that.
 */
class AssistantContext
{
    public static function forStudent(User $student): string
    {
        $lines = [];

        $applications = Application::whereIn('module_type', ['gra_application', 'ga_application'])
            ->where('student_id', $student->id)
            ->orderByDesc('submitted_at')
            ->get();

        if ($applications->isEmpty()) {
            $lines[] = 'No GRA or GA application on record.';
        }

        foreach ($applications as $application) {
            $lines[] = self::applicationSummary($application);
        }

        $allowance = self::allowanceSummary($student);
        if ($allowance) {
            $lines[] = $allowance;
        }

        $taHours = (float) TaHourLog::where('student_id', $student->id)
            ->where('activity_date', '>=', Carbon::today()->startOfMonth())
            ->sum('hours');
        $lines[] = "TA hours this month: {$taHours} of 21 allowed.";

        return implode("\n\n", $lines);
    }

    protected static function applicationSummary(Application $application): string
    {
        $detail = $application->module_type === 'gra_application'
            ? GraApplicationDetail::where('application_id', $application->id)->first()
            : GaApplicationDetail::where('application_id', $application->id)->first();

        $line = "{$application->reference()} ({$application->module_type}): status={$application->status}, current stage={$application->current_stage}.";

        if ($detail) {
            $line .= " Candidature status: {$detail->candidature_status}.";
        }

        // GA's interview result/notes -- confirmed visible to the student
        // on this same show.blade.php template today, so including it here
        // matches what the real page already tells them, nothing extra.
        if ($detail instanceof GaApplicationDetail && $detail->interview_result) {
            $line .= " Research Centre interview result: {$detail->interview_result}.";
            if ($detail->interview_notes) {
                $line .= " Interview notes: {$detail->interview_notes}.";
            }
        }

        $gates = StageGate::where('application_id', $application->id)->orderBy('stage_order')->get();

        if ($gates->isNotEmpty()) {
            $line .= ' Stage Gates: '.$gates->map(fn (StageGate $g) => "Stage {$g->stage_order} ({$g->stage_name}): completion={$g->completion_status}, timeline={$g->timeline_status}, deadline={$g->deadline_date->format('d M Y')}."
            )->implode(' ');
        }

        // Also confirmed visible to the student on the same template --
        // "tak patut student tahu" never applied to these, they're the
        // reason the student gets an edit form at all.
        $corrections = ApplicationCorrectionRequest::where('application_id', $application->id)
            ->whereNull('resolved_at')
            ->get();

        if ($corrections->isNotEmpty()) {
            $line .= ' Open correction requests: '.$corrections->map(fn (ApplicationCorrectionRequest $c) => "{$c->target_label} ({$c->remarks})"
            )->implode('; ').'.';
        }

        $appeal = CandidatureAppeal::where('application_id', $application->id)->latest('id')->first();

        if ($appeal) {
            $line .= " Candidature appeal: status={$appeal->status}.";
            if ($appeal->decision_notes) {
                $line .= " Decision notes: {$appeal->decision_notes}.";
            }
        }

        return $line;
    }

    protected static function allowanceSummary(User $student): ?string
    {
        $month = Carbon::today()->startOfMonth();

        $log = AllowancePaymentLog::where('student_id', $student->id)
            ->where('payment_month', '<=', $month)
            ->orderByDesc('payment_month')
            ->first();

        if (! $log) {
            return null;
        }

        $line = "Allowance, most recent record ({$log->payment_month->format('M Y')}): status={$log->status}, amount=RM"
            .number_format((float) $log->amount, 2).'.';

        // GA has no allowance tracking at all (GraApplicationDetail only),
        // so a null rate here is routine for a GA student, not a data gap.
        $rate = GraApplicationDetail::where('application_id', $log->application_id)->value('proposed_allowance');

        if ($rate !== null) {
            $line .= ' Monthly rate once eligible: RM'.number_format((float) $rate, 2).'.';

            $projected = AllowanceLedger::projectedNextMonthIfUnconfirmed(
                (int) $log->application_id,
                Carbon::parse($log->payment_month),
                (float) $rate
            );

            if ($projected !== null) {
                $line .= " If this month's figure above stays unconfirmed by CGS/Admin, next month's running total would be RM"
                    .number_format($projected, 2).'.';
            }
        }

        return $line;
    }
}
