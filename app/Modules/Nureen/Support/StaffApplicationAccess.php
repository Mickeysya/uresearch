<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;

/**
 * Which staff may open the read-only staff view of one of this module's
 * applications (nureen.application) -- the page every staff email links
 * to, since Core's applications.show is students-only.
 *
 * Only staff with a genuine part in THIS application (rule 8, scope every
 * query):
 *   - the supervisor named on it (GA Extension, Certification) or requested
 *     (Supervision);
 *   - anyone who has recorded a decision on it;
 *   - a role that owns a stage of its own chain -- a department-scoped role
 *     (Chair) only for the student's own department;
 *   - for GA Extension, the Senior Director, who is emailed every outcome
 *     for information even when not on the chain.
 */
class StaffApplicationAccess
{
    public const MODULES = ['ga_extension', 'ga_certification', 'supervision', 'attendance_appeal'];

    public static function allows(User $user, Application $application): bool
    {
        if ($user->isStudent() || ! in_array($application->module_type, self::MODULES, true)) {
            return false;
        }

        $named = match ($application->module_type) {
            'ga_extension' => GaExtensionDetail::where('application_id', $application->id)->value('supervisor_id'),
            'ga_certification' => GaCertificationDetail::where('application_id', $application->id)->value('supervisor_id'),
            'supervision' => SupervisionDetail::where('application_id', $application->id)->value('requested_supervisor_id'),
            default => null,
        };

        if ($named === $user->id || $application->history()->where('approver_id', $user->id)->exists()) {
            return true;
        }

        if ($application->module_type === 'ga_extension' && $user->role === Role::SENIOR_DIRECTOR_CGS) {
            return true;
        }

        foreach (app(WorkflowEngine::class)->stagesFor($application) as $stage) {
            if ($stage->role !== $user->role || $user->role === Role::SUPERVISOR) {
                continue;   // a supervisor is allowed only as the named one, above
            }

            if (! Role::isDepartmentScoped($stage->role) || $user->department === $application->student?->department) {
                return true;
            }
        }

        return false;
    }
}
