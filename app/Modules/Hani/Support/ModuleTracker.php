<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\ReVivaDetail;

/**
 * The step tracker shown at the top of Hani's pages, one per module.
 *
 * Every step's state comes from stored status, never from which page is
 * open. A step is completed when its own condition holds; the CURRENT step
 * is the first one that is not, and everything after it is upcoming, except
 * a later step that is already done stays ticked (the examiner nomination
 * can finish before the softbound, for instance).
 *
 * Each method returns what hani::partials.module_stepper draws:
 *   ['title' => ?string, 'now' => ?string,
 *    'steps' => [['label' => string, 'state' => completed|current|upcoming|rejected, 'url' => ?string]]]
 */
final class ModuleTracker
{
    /**
     * Viva, the student's view: Notice → Softbound → Examiner Appointment →
     * Viva Session → Result & Corrections. Examiner appointment is a status
     * only: a candidate never sees who their examiners are.
     */
    public static function viva(VivaProgress $p, bool $eligible = false): array
    {
        $notice = $p->notice;
        $vivaAt = $p->detail?->viva_at;

        $done = [
            'notice' => $notice?->status === Application::STATUS_APPROVED,
            'softbound' => $p->softbound?->status === Application::STATUS_APPROVED,
            'examiners' => $p->nomination?->status === Application::STATUS_APPROVED,
            'session' => $vivaAt !== null && $vivaAt->isPast(),
            // Complete once the AE accepts the corrected thesis (categories 1-3).
            'result' => $p->detail?->corrections_accepted_at !== null,
        ];
        $d = $p->detail;

        $now = [
            'notice' => match (true) {
                $notice?->status === Application::STATUS_PENDING => 'Your Academic Executive is reviewing your Notice of Thesis Submission. Nothing to do until they acknowledge it.',
                $eligible => 'Submit your Notice of Thesis Submission to continue.',
                default => 'Tick every requirement on the eligibility checklist, then submit your Notice of Thesis Submission.',
            },
            'softbound' => match (true) {
                $p->softboundReturnReason() !== null => 'Your softbound thesis was returned. Fix what your Academic Executive asked for, then resubmit it.',
                $p->softbound?->status === Application::STATUS_PENDING => 'Your Academic Executive is checking your softbound thesis. Nothing to do until they verify it.',
                default => 'Upload your softbound thesis (Form #019, PDF) to continue.',
            },
            'examiners' => 'Your supervisor is nominating your examiners ('.lcfirst($p->nominationLabel()).'). Nothing to do until they are appointed.',
            'session' => $vivaAt
                ? 'Attend your viva on '.$vivaAt->format('l, j M Y, g:i a').' at '.$p->detail->venue.'.'
                : 'Your Academic Executive will set your viva date and email you a calendar invitation.',
            'result' => match (true) {
                ! $d?->hasResult() => 'Your Academic Executive will share the viva report about a week after the viva, once the chairman sends it.',
                $d->needsCorrections() && $d->corrections_submitted_at !== null => 'Your Academic Executive is checking your corrected thesis. Nothing to do until they accept it.',
                $d->needsCorrections() => 'Result '.$d->resultLabel().': upload your corrected final thesis by '.$d->corrections_due_at->format('j M Y').'.',
                $d->result_category === \App\Modules\Hani\Models\VivaDetail::REEXAMINATION => 'Result '.$d->resultLabel().': revise your thesis and resubmit it to CGS between '
                    .$d->resubmission_opens_at->format('j M Y').' and '.$d->resubmission_closes_at->format('j M Y').'. CGS will then log your re-viva.',
                default => 'Result '.$d->resultLabel().': this is the final outcome. Contact your Academic Executive if you have questions.',
            },
        ];

        $urls = [
            'notice' => $notice ? route('applications.show', $notice) : null,
            'softbound' => $p->softbound ? route('applications.show', $p->softbound) : null,
        ];

        $tracker = self::linear([
            'notice' => 'Notice of Thesis Submission',
            'softbound' => 'Softbound Thesis',
            'examiners' => 'Examiner Appointment',
            'session' => 'Viva Session',
            'result' => 'Result & Corrections',
        ], $done, $now, $urls);

        // A fail is final: a cross, with its own line, whatever came before.
        if ($d?->result_category === \App\Modules\Hani\Models\VivaDetail::FAIL) {
            $tracker['steps'][4]['state'] = 'rejected';
            $tracker['now'] = $now['result'];
        }

        return $tracker;
    }

    /**
     * Examiner Matching, the staff view: the real six stages of
     * ExaminerNominationWorkflow, read from the engine so this can never
     * disagree with where the nomination actually is.
     */
    public static function nomination(Application $nomination): array
    {
        $steps = array_map(fn ($step) => [
            'label' => $step['stage']->label,
            'state' => $step['state'],
            'url' => null,
        ], $nomination->progress());

        $now = match ($nomination->status) {
            Application::STATUS_APPROVED => 'Released. The examiners are appointed and the viva can be scheduled.',
            Application::STATUS_REJECTED => 'Not approved at '.($nomination->currentStage()?->label ?? 'a stage').'. The supervisor files a new nomination.',
            default => 'With '.($nomination->currentStage()?->label ?? 'the next stage').'.',
        };

        return ['title' => null, 'now' => $now, 'steps' => $steps];
    }

    /**
     * Re-viva, the AE's view of one cycle: Resubmission → Panel Review →
     * Report & Consolidation → Result. Level 1-3 is a pass with corrections,
     * level 4 sends the student into another round, level 5 is final.
     */
    public static function reviva(ReVivaDetail $detail): array
    {
        $application = $detail->application;
        $stage = $application?->current_stage;
        $finished = $application?->status === Application::STATUS_APPROVED;
        $level = $detail->outcome_level;

        $done = [
            'resubmission' => true,
            'panel' => $finished || in_array($stage, ['report_received', 'consolidation_scheduled'], true),
            'consolidation' => $finished,
            'result' => $level !== null,
        ];

        $now = [
            'resubmission' => null,
            'panel' => 'Send the re-viva report to the panel and track their review.',
            'consolidation' => 'Record the panel\'s report and schedule the consolidation meeting.',
            'result' => 'Record the outcome level under Re-viva Outcomes.',
        ];

        $tracker = self::linear([
            'resubmission' => 'Resubmission',
            'panel' => 'Panel Review',
            'consolidation' => 'Report & Consolidation',
            'result' => 'Re-viva Result',
        ], $done, $now);

        $tracker['title'] = 'Re-viva round '.$detail->cycle_number;

        if ($level !== null) {
            // A fail or a dismissal reads as a cross, not a tick.
            if ($level >= ReVivaDetail::OUTCOME_LOOP_BACK) {
                $tracker['steps'][3]['state'] = 'rejected';
            }

            $tracker['now'] = match (true) {
                $level === ReVivaDetail::OUTCOME_DISMISSED => 'Level 5: dismissed. This is the final outcome; there is no further action.',
                $level === ReVivaDetail::OUTCOME_LOOP_BACK => 'Level 4: another round is needed. CGS logs re-viva round '.($detail->cycle_number + 1).' when the corrected thesis arrives.',
                default => 'Level '.$level.': passed with corrections, due by '.$detail->correction_deadline->format('j M Y').'.',
            };
        }

        return $tracker;
    }

    /**
     * Turn per-step "is it done" flags into states: the first step not done
     * is current, later ones are upcoming unless already done themselves.
     *
     * @param  array<string, string>  $labels  key => label, in order
     * @param  array<string, bool>  $done
     * @param  array<string, ?string>  $now  the "what to do now" line per step
     * @param  array<string, ?string>  $urls  a completed step's view-only page
     */
    protected static function linear(array $labels, array $done, array $now, array $urls = []): array
    {
        $current = null;
        $steps = [];

        foreach ($labels as $key => $label) {
            $state = match (true) {
                $current === null && ! $done[$key] => 'current',
                $done[$key] => 'completed',
                default => 'upcoming',
            };

            if ($state === 'current') {
                $current = $key;
            }

            $steps[] = [
                'label' => $label,
                'state' => $state,
                // Only a completed step links anywhere; upcoming ones are not clickable.
                'url' => $state === 'completed' ? ($urls[$key] ?? null) : null,
            ];
        }

        return ['title' => null, 'now' => $current ? $now[$current] : null, 'steps' => $steps];
    }
}
