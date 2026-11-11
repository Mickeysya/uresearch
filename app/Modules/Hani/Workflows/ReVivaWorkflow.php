<?php

namespace App\Modules\Hani\Workflows;

use App\Modules\Core\Contracts\DecidesOneAtATime;
use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\SuppliesCalendarEvents;
use App\Modules\Core\Contracts\SuppliesSignature;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Hani\Models\ReVivaDetail;
use Carbon\CarbonInterface;

/**
 * The official CGS re-examination process.
 *
 * The student submits; the Senior Executive and the Dean of PGR move it along.
 * A return to the student is the engine's 'return' (status returned, same
 * stage), and the result is the approved status after the last stage, so
 * those two steps of the 10-step stepper are not engine stages. Meeting
 * Scheduled joins the chain only for a round with a meeting.
 *
 * DecidesOneAtATime: every stage does more than call the engine.
 */
class ReVivaWorkflow implements WorkflowModule, ProvidesLinks, SuppliesCalendarEvents, DecidesOneAtATime, SuppliesSignature
{
    public function key(): string
    {
        return 're_viva';
    }

    public function label(): string
    {
        return 'Re-viva Monitoring';
    }

    public function stages(?Application $application = null): array
    {
        $stages = [
            new Stage('student_submitted', 'Document Check', Role::SENIOR_EXEC_CGS, 'accepted', queueTitle: 'Document Check'),
            new Stage('pack_preparation', 'Pack Preparation', Role::SENIOR_EXEC_CGS, 'prepared', queueTitle: 'Pack Preparation'),
            new Stage('dean_approval', 'Dean Approval', Role::DEAN_PGR, 'approved', queueTitle: 'Re-viva Approval'),
            new Stage('ready_to_send', 'Ready to Send', Role::SENIOR_EXEC_CGS, 'sent', queueTitle: 'Ready to Send'),
            new Stage('under_panel_review', 'Under Panel Review', Role::SENIOR_EXEC_CGS, 'reviewed', queueTitle: 'Under Panel Review'),
            new Stage('reports_received', 'Reports Received', Role::SENIOR_EXEC_CGS, 'received', queueTitle: 'Reports Received'),
            new Stage('cgs_review', 'CGS Review', Role::SENIOR_EXEC_CGS, 'reviewed', queueTitle: 'CGS Review'),
        ];

        if ($application === null || ReVivaDetail::where('application_id', $application->id)->whereNotNull('meeting_kind')->exists()) {
            $stages[] = new Stage('meeting_scheduled', 'Meeting Scheduled', Role::SENIOR_EXEC_CGS, 'held', queueTitle: 'Meetings');
        }

        return $stages;
    }

    public function summary(Application $application): string
    {
        $detail = ReVivaDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'Re-viva monitoring';
        }

        return "Round {$detail->cycle_number}, submitted ".$detail->resubmission_at->format('j M Y');
    }

    public function createRoute(): ?string
    {
        return null;
    }

    public function queueRoute(): string
    {
        return 'reviva.queue';
    }

    /** Links the stage queues cannot produce. No link at all for the Academic Executive. */
    public function links(User $user): array
    {
        return match (true) {
            $user->role === Role::SENIOR_EXEC_CGS => [
                ['label' => 'Re-viva Cycles', 'route' => 'reviva.cycles.index'],
                ['label' => 'Re-viva Outcomes', 'route' => 'reviva.outcomes.index'],
                ['label' => 'Re-viva Settings', 'route' => 'reviva.settings'],
            ],
            $user->role === Role::NON_EXEC_CGS => [['label' => 'Re-viva Cycles', 'route' => 'reviva.cycles.index']],
            $user->role === Role::ACADEMIC_EXEC => [],
            $user->role === Role::STUDENT => Application::where('module_type', $this->key())->where('student_id', $user->id)->exists()
                    || \App\Modules\Hani\Support\ReVivaWindow::categoryFourViva($user)
                ? [['label' => 'Re-viva', 'route' => 'reviva.mine']]
                : [],
            \App\Modules\Hani\Support\ReVivaProcess::examinesOpenRound($user) => [['label' => 'My Re-evaluations', 'route' => 'reviva.examiner.index']],
            default => [],
        };
    }

    /**
     * The two deadlines that run from the resubmission timestamp.
     *
     * These are the hardest dates in the portal -- 6 months to submit
     * corrections, 12 to hardbound -- and they are exactly why the timestamp
     * is stamped once and stored rather than recomputed. A student whose
     * thesis is in a re-viva cycle should not have to open the application to
     * find out when they run out of time.
     */
    public function calendarEvents(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($user->role !== Role::STUDENT) {
            return [];
        }

        $details = ReVivaDetail::query()
            ->whereIn('application_id', Application::query()
                ->where('module_type', $this->key())
                ->where('student_id', $user->id)
                ->select('id'))
            // A round with a final outcome has stopped running; levels 1-3
            // still have the final thesis due (correction_deadline).
            ->where(fn ($q) => $q->whereNull('outcome_level')->orWhereIn('outcome_level', ReVivaDetail::OUTCOME_CORRECTIONS))
            ->whereNull('corrections_accepted_at')
            ->get();

        $events = [];

        foreach ($details as $detail) {
            foreach ([
                ['correction_deadline', 'Final thesis due', 'warn'],
                ['hardbound_deadline', 'Hardbound submission due', 'critical'],
            ] as [$field, $title, $tone]) {
                if (! $detail->$field?->betweenIncluded($from, $to)) {
                    continue;
                }

                $events[] = [
                    'date' => $detail->$field,
                    'title' => $title,
                    'tone' => $tone,
                    'meta' => 'Re-viva cycle '.$detail->cycle_number,
                    'url' => route('applications.show', $detail->application_id),
                ];
            }
        }

        return $events;
    }

    /** The Dean signs the re-viva correction letters: Approve is blocked without it. */
    public function signatureFor(User $user): ?array
    {
        if ($user->role !== Role::DEAN_PGR) {
            return null;
        }

        $signature = \App\Modules\Hani\Models\ReVivaSignature::forUser($user->id);

        return [
            'key' => 're-viva',
            'title' => 'Re-viva Correction Letters',
            'usedFor' => 'Stamped onto each Correction of Thesis letter when you approve a re-viva pack.',
            'required' => true,
            'imageUrl' => $signature ? route('reviva.signature.image') : null,
            'updatedAt' => $signature?->updated_at,
            'storeRoute' => 'reviva.signature.store',
            'destroyRoute' => null,
            'hint' => 'PNG or JPG, under 1 MB',
        ];
    }
}
