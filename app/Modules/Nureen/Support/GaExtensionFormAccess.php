<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Nureen\Models\GaExtensionDetail;

/**
 * Who may open the generated GA Extension Form (ga-extension.form-pdf and
 * .form-pdf.view): the owner; and once submitted, the supervisor it is
 * addressed to (or who endorsed it, if that has since changed) and anyone
 * holding the role of another stage on this application's own chain.
 *
 * Moved here, unchanged, from GaExtensionController::canViewForm() so the
 * Documents page can list the form under exactly the rule the route
 * enforces. The controller still calls canViewForm(), which delegates here.
 */
class GaExtensionFormAccess
{
    public static function allows(User $user, Application $application): bool
    {
        if ($application->student_id === $user->id) {
            return true;
        }

        if ($application->status === Application::STATUS_DRAFT) {
            return false;
        }

        $detail = GaExtensionDetail::where('application_id', $application->id)->first();

        if ($detail?->supervisor_id === $user->id
            || ApprovalHistory::where('application_id', $application->id)->where('stage_key', 'supervisor')->where('approver_id', $user->id)->exists()) {
            return true;
        }

        foreach (app(WorkflowEngine::class)->stagesFor($application) as $stage) {
            if ($stage->key !== 'supervisor' && $stage->role === $user->role) {
                return true;
            }
        }

        return false;
    }
}
