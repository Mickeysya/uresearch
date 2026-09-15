<?php

namespace App\Modules\Nureen\Workflows;

use App\Modules\Core\Contracts\SuppliesCalendarEvents;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Models\GaExtensionDetail;
use Illuminate\Support\Str;
use App\Modules\Core\Models\User;
use Carbon\CarbonInterface;

/**
 * Graduate Assistantship extension requests.
 *
 * Three fixed stages: Supervisor endorses, CGS verifies, Senior Director
 * decides.
 *
 * Ported from the legacy ga_extension_* pages, which stored their position in
 * current_stage as 'supervisor_approved' / 'cgs_verified' while keeping status
 * at 'pending' throughout. That vocabulary did not match the other modules, so
 * the shared tracking page rendered every GA application as a Claims record
 * stuck on step 1. Declaring the chain here puts it on the same footing as
 * everything else.
 */
class GaExtensionWorkflow implements WorkflowModule, SuppliesCalendarEvents
{
    public function key(): string
    {
        return 'ga_extension';
    }

    public function label(): string
    {
        return 'GA Extension & VISA';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage(
                key: 'supervisor',
                label: 'Lecturer/Supervisor',
                role: Role::SUPERVISOR,
                decision: 'endorsed',
                queueTitle: 'Pending My Endorsement',
            ),
            new Stage(
                key: 'cgs_verify',
                label: 'CGS Staff',
                role: Role::NON_EXEC_CGS,
                decision: 'reviewed',
                queueTitle: 'Pending My Verification',
            ),
            new Stage(
                key: 'senior_director',
                label: 'Senior Director CGS',
                role: Role::SENIOR_DIRECTOR_CGS,
                decision: 'approved',
                queueTitle: 'Final Approval',
            ),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = GaExtensionDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'GA extension request';
        }

        return 'Extend to '.$detail->requested_new_end_date->format('j M Y')
            .' — '.Str::limit($detail->reason_for_extension, 70);
    }

    public function createRoute(): ?string
    {
        return 'ga-extension.create';
    }

    public function queueRoute(): string
    {
        return 'ga-extension.queue';
    }

    /**
     * When the student's assistantship actually runs out.
     *
     * The requested new end date is shown only once it has been approved --
     * before that it is a request, not a date, and putting it on a calendar
     * would tell the student they have an extension they have not been given.
     */
    public function calendarEvents(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($user->role !== Role::STUDENT) {
            return [];
        }

        $applications = Application::query()
            ->where('module_type', $this->key())
            ->where('student_id', $user->id)
            ->whereIn('status', [Application::STATUS_PENDING, Application::STATUS_APPROVED])
            ->get()
            ->keyBy('id');

        $details = GaExtensionDetail::whereIn('application_id', $applications->keys())->get();

        $events = [];

        foreach ($details as $detail) {
            $approved = $applications[$detail->application_id]->status === Application::STATUS_APPROVED;
            $date = $approved ? $detail->requested_new_end_date : $detail->current_end_date;

            if (! $date?->betweenIncluded($from, $to)) {
                continue;
            }

            $events[] = [
                'date' => $date,
                'title' => $approved ? 'GA appointment ends (extended)' : 'GA appointment ends',
                'tone' => 'warn',
                'meta' => $approved ? null : 'Extension still under review',
                'url' => route('applications.show', $detail->application_id),
            ];
        }

        return $events;
    }
}
