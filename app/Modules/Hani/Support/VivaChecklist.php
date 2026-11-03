<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\VivaEligibility;

/**
 * The gating checks before a Notice of Thesis Submission, from the UTP
 * Postgraduate Handbook 2025, and the one place that decides whether a
 * student has met them.
 *
 * Self-declared for now. When the "verified by AE" step lands, isEligible()
 * is the only thing that needs to start looking at verified_at.
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

    /** Maximum candidacy in months: [full-time, part-time]. */
    protected const CANDIDACY_MONTHS = [
        self::MSC => [48, 72],
        self::PHD => [96, 120],
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

        $months = self::CANDIDACY_MONTHS[$level][self::isPartTime($student) ? 1 : 0];
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
                'label' => 'I am registered for the current semester',
                'hint' => 'Check your registration status on the student portal.',
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

    /** Every check that applies to this student is ticked. */
    public static function isEligible(User $student, ?VivaEligibility $record = null): bool
    {
        $items = self::itemsFor($student);
        $record ??= self::recordFor($student);

        if ($items === [] || ! $record) {
            return false;
        }

        foreach (array_keys($items) as $column) {
            if (! $record->$column) {
                return false;
            }
        }

        return true;
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
