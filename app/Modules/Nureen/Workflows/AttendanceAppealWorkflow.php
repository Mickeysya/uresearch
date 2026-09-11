<?php

namespace App\Modules\Nureen\Workflows;

use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Models\AttendanceAppealDetail;
use Illuminate\Support\Str;

/**
 * A student's dispute of their recorded/flagged attendance. Single stage,
 * per the scope: "routes to CGS staff for review" -- no supervisor sign-off
 * in between.
 *
 * Also carries the CGS-facing sidebar links for Attendance (CSV upload, the
 * at-risk dashboard), since neither is a create/queue pair a WorkflowModule
 * already exposes. A student's own Overview/History pair lives in the app's
 * fixed sidebar tree instead, not declared here.
 */
class AttendanceAppealWorkflow implements WorkflowModule, ProvidesLinks
{
    public function key(): string
    {
        return 'attendance_appeal';
    }

    public function label(): string
    {
        return 'Attendance Appeal';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage(
                key: 'cgs_review',
                label: 'Non-Executive CGS',
                role: Role::NON_EXEC_CGS,
                decision: 'approved',
                queueTitle: 'Pending My Review',
            ),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = AttendanceAppealDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'Attendance appeal';
        }

        return Str::limit($detail->reason, 80);
    }

    public function createRoute(): ?string
    {
        return 'attendance-appeal.create';
    }

    public function queueRoute(): string
    {
        return 'attendance-appeal.queue';
    }

    public function links(User $user): array
    {
        return match ($user->role) {
            Role::NON_EXEC_CGS => [
                ['label' => 'Upload Attendance CSV', 'route' => 'attendance.upload.form'],
                ['label' => 'At-Risk Students', 'route' => 'attendance.at-risk'],
            ],
            default => [],
        };
    }
}
