<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Nureen\Models\GaExtensionDetail;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The GA Extension Form's "Graduate Assistant (GA) Scheme Terms and
 * Conditions", as checks on the approver's System checks panel.
 *
 * Each check is pass, fail or unknown (a field it needs was not given --
 * an application submitted before the form was generated has none of
 * Section B). They inform the decision; they do not make it. The one rule
 * the terms call absolute -- "Backdated appointment ... is not allowed" --
 * is enforced at submission instead (GaExtensionController::store()).
 *
 * Every number comes from config('nureen.ga_extension.rules').
 */
class GaExtensionRules
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const UNKNOWN = 'unknown';

    /**
     * @param  Collection<int, \App\Modules\Core\Models\ApplicationDocument>  $documents  the application's attached documents
     * @return array<int, array{key: string, label: string, status: string, reason: string}>
     */
    public function check(GaExtensionDetail $detail, Application $application, Collection $documents): array
    {
        return [
            $this->totalDuration($detail),
            $this->extensionLength($detail),
            $this->startDay($detail),
            $this->endDay($detail),
            $this->notice($detail, $application),
            $this->stageGate($detail, $documents),
        ];
    }

    /** (iii) Total GA duration from admission within the level's maximum. */
    public function totalDuration(GaExtensionDetail $detail): array
    {
        $label = 'Total GA duration within the maximum';
        $max = config("nureen.ga_extension.rules.max_months.{$detail->level_of_study}");

        if (! $detail->admission_date || ! $max || ! $detail->requested_new_end_date) {
            return $this->unknown('total_duration', $label, 'Needs the admission date, level of study and requested end date.');
        }

        $months = GaExtensionDetail::monthsBetween($detail->admission_date, $detail->requested_new_end_date);
        $level = $detail->optionLabel('level_of_study');
        $span = "{$this->months($months)} from admission ({$detail->admission_date->format('j M Y')}) to {$detail->requested_new_end_date->format('j M Y')}";

        return $months <= $max
            ? $this->result('total_duration', $label, self::PASS, "{$span}; the {$level} maximum is {$max} months.")
            : $this->result('total_duration', $label, self::FAIL, "{$span}, over the {$level} maximum of {$max} months.");
    }

    /** (iii) The extension itself between the minimum and maximum length. */
    public function extensionLength(GaExtensionDetail $detail): array
    {
        $label = 'Extension length';
        $min = config('nureen.ga_extension.rules.extension_months.min');
        $max = config('nureen.ga_extension.rules.extension_months.max');
        $months = $detail->extensionMonths();

        if ($months === null) {
            return $this->unknown('extension_length', $label, 'Needs the current and requested end dates.');
        }

        return $months >= $min && $months <= $max
            ? $this->result('extension_length', $label, self::PASS, "{$this->months($months)}, within {$min}–{$max} months.")
            : $this->result('extension_length', $label, self::FAIL, "{$this->months($months)}; an extension must be {$min}–{$max} months.");
    }

    /** (iii) The new appointment starts on an allowed day (1st or 16th). */
    public function startDay(GaExtensionDetail $detail): array
    {
        $label = 'Appointment starts on an allowed day';
        $start = $detail->extensionStart();

        if (! $start) {
            return $this->unknown('start_day', $label, 'Needs the current end date.');
        }

        $allowed = $this->dayList(config('nureen.ga_extension.rules.start_days'));

        return $this->dayAllowed($start, config('nureen.ga_extension.rules.start_days'))
            ? $this->result('start_day', $label, self::PASS, "Starts {$start->format('j M Y')}.")
            : $this->result('start_day', $label, self::FAIL, "Starts {$start->format('j M Y')} (the day after the current end date); it must start on the {$allowed}.");
    }

    /** (iii) The new appointment ends on an allowed day (15th or month end). */
    public function endDay(GaExtensionDetail $detail): array
    {
        $label = 'Appointment ends on an allowed day';
        $end = $detail->requested_new_end_date;

        if (! $end) {
            return $this->unknown('end_day', $label, 'Needs the requested end date.');
        }

        $allowed = $this->dayList(config('nureen.ga_extension.rules.end_days'));

        return $this->dayAllowed($end, config('nureen.ga_extension.rules.end_days'))
            ? $this->result('end_day', $label, self::PASS, "Ends {$end->format('j M Y')}.")
            : $this->result('end_day', $label, self::FAIL, "Ends {$end->format('j M Y')}; it must end on the {$allowed}.");
    }

    /** (iii) Submitted at least notice_months before the new appointment starts. */
    public function notice(GaExtensionDetail $detail, Application $application): array
    {
        $label = 'Submitted early enough';
        $start = $detail->extensionStart();
        $notice = (int) config('nureen.ga_extension.rules.notice_months');
        $period = $notice === 1 ? 'one month' : "{$notice} months";

        if (! $start) {
            return $this->unknown('notice', $label, 'Needs the current end date.');
        }

        // A draft has not been submitted yet: judged as if submitted now.
        $submitted = $application->submitted_at ?? now();
        $latest = $start->copy()->subMonthsNoOverflow($notice);
        $verb = $application->submitted_at ? 'Submitted' : 'Not yet submitted; today is';

        return $submitted->copy()->startOfDay()->lte($latest)
            ? $this->result('notice', $label, self::PASS, "{$verb} {$submitted->format('j M Y')}, at least {$period} before the {$start->format('j M Y')} start.")
            : $this->result('notice', $label, self::FAIL, "{$verb} {$submitted->format('j M Y')}; it should have been by {$latest->format('j M Y')}, {$period} before the {$start->format('j M Y')} start.");
    }

    /**
     * (vii)/(viii) The stage gate level at the extension's start, and
     * whether the proof that level needs is attached.
     *
     * @param  Collection<int, \App\Modules\Core\Models\ApplicationDocument>  $documents
     */
    public function stageGate(GaExtensionDetail $detail, Collection $documents): array
    {
        $label = 'Stage gate proof attached';
        $start = $detail->extensionStart();
        $gates = config("nureen.ga_extension.rules.stage_gates.{$detail->level_of_study}");

        if (! $detail->admission_date || ! $gates || ! $start) {
            return $this->unknown('stage_gate', $label, 'Needs the admission date, level of study and current end date.');
        }

        $months = self::monthsCompleted($detail->admission_date, $start);
        $level = self::stageGateLevel($detail->level_of_study, $months);
        $at = "{$months} ".($months === 1 ? 'month' : 'months')." since admission at the {$start->format('j M Y')} start";

        if ($level === 0) {
            return $this->result('stage_gate', $label, self::PASS, "{$at}: before the first stage gate, nothing to attach.");
        }

        $gate = $gates[$level];
        $head = "{$at}: Level {$level} ({$gate['requirement']})";

        if (! $gate['proof']) {
            return $this->result('stage_gate', $label, self::PASS, "{$head}. Nothing to attach; CGS confirms it from the student record.");
        }

        $proof = config("nureen.ga_extension.stage_gate_documents.{$gate['proof']}");
        $byType = $documents->groupBy('doc_type');

        if ($byType->has($proof)) {
            return $this->result('stage_gate', $label, self::PASS, "{$head}. \"{$proof}\" is attached.");
        }

        // An application submitted before proofs were split by type.
        $legacy = config('nureen.ga_extension.legacy_documents.stage_gate_proof');
        if (in_array($gate['proof'], ['acceptance_proof_documents', 'published_article_documents'], true) && $byType->has($legacy)) {
            return $this->result('stage_gate', $label, self::PASS, "{$head}. \"{$legacy}\" is attached (uploaded before proofs were split by type): check it is the right one.");
        }

        return $this->result('stage_gate', $label, self::FAIL, "{$head}. \"{$proof}\" is not attached.");
    }

    /** Whole months completed between $admission and $at. */
    public static function monthsCompleted(CarbonInterface $admission, CarbonInterface $at): int
    {
        return max(0, (int) floor($admission->copy()->startOfDay()->diffInMonths($at->copy()->startOfDay())));
    }

    /**
     * The highest level whose 'from' month is at or below $months; 0 before
     * the first. A boundary month belongs to the later level.
     */
    public static function stageGateLevel(string $levelOfStudy, int $months): int
    {
        $level = 0;

        foreach (config("nureen.ga_extension.rules.stage_gates.{$levelOfStudy}", []) as $number => $gate) {
            if ($months >= $gate['from']) {
                $level = max($level, (int) $number);
            }
        }

        return $level;
    }

    /** @param  array<int, int|string>  $days */
    protected function dayAllowed(CarbonInterface $date, array $days): bool
    {
        foreach ($days as $day) {
            if ($day === 'last' ? $date->isLastOfMonth() : $date->day === (int) $day) {
                return true;
            }
        }

        return false;
    }

    /** [1, 16] => "1st or 16th"; [15, 'last'] => "15th or last day of the month". */
    protected function dayList(array $days): string
    {
        $words = array_map(fn ($day) => $day === 'last' ? 'last day of the month' : $day.$this->ordinal((int) $day), $days);

        return implode(' or ', $words);
    }

    protected function ordinal(int $n): string
    {
        return in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
    }

    protected function months(float $months): string
    {
        $shown = fmod($months, 1.0) === 0.0 ? (int) $months : $months;

        return $shown.' '.($shown == 1 ? 'month' : 'months');
    }

    protected function unknown(string $key, string $label, string $reason): array
    {
        return $this->result($key, $label, self::UNKNOWN, $reason);
    }

    protected function result(string $key, string $label, string $status, string $reason): array
    {
        return compact('key', 'label', 'status', 'reason');
    }
}
