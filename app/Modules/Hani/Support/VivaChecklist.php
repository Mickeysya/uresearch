<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\VivaEligibility;
use App\Modules\Hani\Support\Eligibility\EligibilityResult;
use App\Modules\Hani\Support\Eligibility\EligibilitySource;

/**
 * The gating checks before a Notice of Thesis Submission, from the UTP
 * Postgraduate Handbook 2025, and the one place that decides whether a
 * student has met them.
 *
 * Most items are self-declared. Those an EligibilitySource can check (the
 * candidacy period, from the candidature start date) are checked by the
 * system instead: a verified item needs no tick, and an item the system finds
 * not met blocks the notice whatever was ticked. The AE confirms the lot on
 * verification (viva_eligibility.verified_by_id / verified_at).
 */
final class VivaChecklist
{
    public const MSC = 'MSC';

    public const PHD = 'PHD';

    /** Every check column on viva_eligibility, whatever the level. */
    public const COLUMNS = [
        'research_methodology_passed', 'publication_accepted', 'rcs_passed',
        'registered_current_semester', 'no_outstanding_fees', 'within_candidacy_period',
    ];

    /**
     * MSC or PHD, read from users.programme ("MSc Full-Time", "PhD Part-Time").
     *
     * Null when the programme is blank, unrecognised, or MSc by Coursework,
     * which has no viva and is out of scope.
     */
    public static function levelFor(User $student): ?string
    {
        $programme = strtolower((string) $student->programme);

        if (str_contains($programme, 'coursework')) {
            return null;
        }

        return match (true) {
            str_contains($programme, 'phd') => self::PHD,
            str_contains($programme, 'msc'), str_contains($programme, 'master') => self::MSC,
            default => null,
        };
    }

    public static function isPartTime(User $student): bool
    {
        return str_contains(strtolower((string) $student->programme), 'part');
    }

    /**
     * The checks that apply to one student, in display order.
     *
     * @return array<string, array{label: string, hint: string}> keyed by column
     */
    public static function itemsFor(User $student): array
    {
        $level = self::levelFor($student);

        if ($level === null) {
            return [];
        }

        $months = (int) config('hani.eligibility.candidacy_months.'.$level.'.'.(self::isPartTime($student) ? 'part_time' : 'full_time'));
        $mode = self::isPartTime($student) ? 'part-time' : 'full-time';

        $items = [
            'research_methodology_passed' => [
                'label' => 'I have passed Research Methodology',
                'hint' => 'Required for both MSc and PhD.',
            ],
            'publication_accepted' => [
                'label' => $level === self::PHD
                    ? 'I have the required publications'
                    : 'I have 1 article accepted in a Scopus or WoS indexed journal',
                'hint' => $level === self::PHD
                    ? '1 article accepted or published in a Q1/Q2 WoS journal, or 2 articles accepted or published in WoS/ERA or Scopus.'
                    : 'Upload the acceptance letter or published article as proof on the notice form.',
            ],
        ];

        if ($level === self::PHD) {
            $items['rcs_passed'] = [
                'label' => 'I have passed my Research Completion Seminar (RCS)',
                'hint' => 'PhD only.',
            ];
        }

        return $items + [
            'registered_current_semester' => [
                // Label only (UAT 11 Nov 2026); the column name is unchanged.
                'label' => 'I am an active student for this semester',
                'hint' => 'Registered for Research Progress or Active Enrolment Status (AES) this semester.',
            ],
            'no_outstanding_fees' => [
                'label' => 'I have no outstanding fees',
                'hint' => 'Including the Examination and Viva Voce fee.',
            ],
            'within_candidacy_period' => [
                'label' => 'I am within my candidacy period',
                'hint' => "Maximum {$months} months for {$mode} candidates.",
            ],
        ];
    }

    public static function recordFor(User $student): ?VivaEligibility
    {
        return VivaEligibility::where('student_id', $student->id)->first();
    }

    /**
     * What the system says about each item that applies to this student:
     * VERIFIED / NOT_MET / FAILED where a source checks it, SELF_DECLARED
     * everywhere else.
     *
     * @return array<string, EligibilityResult> keyed by column, in display order
     */
    public static function systemChecks(User $student): array
    {
        $sources = collect(config('hani.eligibility.sources', []))
            ->map(fn (string $class) => app($class))
            ->filter(fn ($source) => $source instanceof EligibilitySource)
            ->keyBy(fn (EligibilitySource $source) => $source->column());

        $results = [];

        foreach (array_keys(self::itemsFor($student)) as $column) {
            $results[$column] = $sources->has($column)
                ? $sources->get($column)->check($student)
                : EligibilityResult::selfDeclared();
        }

        return $results;
    }

    /**
     * Every item holds: verified by the system, or self-declared and ticked.
     * An item the system found not met, or could not check, fails it.
     *
     * @param  array<string, EligibilityResult>|null  $checks  systemChecks(), if already run
     */
    public static function isEligible(User $student, ?VivaEligibility $record = null, ?array $checks = null): bool
    {
        return self::blockReason($student, $record, $checks) === null;
    }

    /**
     * Why the notice cannot be filed yet, or null if it can.
     *
     * @param  array<string, EligibilityResult>|null  $checks
     */
    public static function blockReason(User $student, ?VivaEligibility $record = null, ?array $checks = null): ?string
    {
        $items = self::itemsFor($student);

        if ($items === []) {
            return 'Your programme does not go through a viva voce.';
        }

        $record ??= self::recordFor($student);
        $checks ??= self::systemChecks($student);

        foreach ($items as $column => $item) {
            $check = $checks[$column] ?? EligibilityResult::selfDeclared();

            if ($check->blocks()) {
                return $check->message ?? 'One requirement is not met.';
            }

            if ($check->status === EligibilityResult::SELF_DECLARED && ! $record?->$column) {
                return 'Confirm every requirement on the checklist before submitting the notice.';
            }
        }

        return null;
    }


    /**
     * A notice already in the chain or finished. A rejected one does not
     * count, so the student can file again after a rejection.
     */
    public static function openNotice(User $student): ?Application
    {
        return Application::query()
            ->where('module_type', 'viva_voce')
            ->where('student_id', $student->id)
            ->whereIn('status', [Application::STATUS_PENDING, Application::STATUS_APPROVED])
            ->latest('id')
            ->first();
    }
}
