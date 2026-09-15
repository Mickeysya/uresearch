<?php

namespace App\Modules\Norhanis\Workflows;

use App\Modules\Core\Contracts\SuppliesCalendarEvents;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Norhanis\Models\PublicationDetail;
use Carbon\CarbonInterface;

/**
 * Conference/journal funding applications and Letters of Undertaking.
 *
 * One fixed chain, no conditional routing: Supervisor -> Chair -> Non-Exec
 * CGS -> Senior Director CGS. Senior Director CGS has the final say.
 */
class PublicationWorkflow implements WorkflowModule, SuppliesCalendarEvents
{
    public function key(): string
    {
        return 'publication';
    }

    public function label(): string
    {
        return 'Publication';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage('supervisor', 'Lecturer/Supervisor', Role::SUPERVISOR, 'endorsed'),
            new Stage('chair', 'Chair of Department', Role::CHAIR, 'endorsed'),
            new Stage('cgs_review', 'Non-Executive CGS', Role::NON_EXEC_CGS, 'reviewed'),
            new Stage('senior_director', 'Senior Director CGS', Role::SENIOR_DIRECTOR_CGS, 'approved'),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = PublicationDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'Publication application';
        }

        return $detail->title_of_paper.' — '.$detail->title_of_conference_journal;
    }

    public function createRoute(): ?string
    {
        return 'publication.create';
    }

    public function queueRoute(): string
    {
        return 'publication.queue';
    }

    /** The student's own conference dates, for applications still live. */
    public function calendarEvents(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($user->role !== Role::STUDENT) {
            return [];
        }

        $details = PublicationDetail::query()
            ->whereIn('application_id', Application::query()
                ->where('module_type', $this->key())
                ->where('student_id', $user->id)
                ->whereIn('status', [Application::STATUS_PENDING, Application::STATUS_APPROVED])
                ->select('id'))
            ->whereNotNull('conference_start_date')
            ->get();

        $events = [];

        foreach ($details as $detail) {
            if (! $detail->conference_start_date?->betweenIncluded($from, $to)) {
                continue;
            }

            $events[] = [
                'date' => $detail->conference_start_date,
                'title' => 'Conference begins',
                'tone' => 'info',
                'meta' => $detail->title_of_conference_journal ?: $detail->title_of_paper,
                'url' => route('applications.show', $detail->application_id),
            ];
        }

        return $events;
    }
}
