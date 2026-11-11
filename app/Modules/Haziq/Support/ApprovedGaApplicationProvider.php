<?php

namespace App\Modules\Haziq\Support;

use App\Modules\Core\Contracts\SuppliesApprovedGaApplication;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Haziq\Models\GaApplicationDetail;
use Illuminate\Support\Carbon;

/**
 * This module's own GA application, as Nureen's GA Extension sees it --
 * see Core\Contracts\SuppliesApprovedGaApplication. A student may only
 * have one approved ga_application at a time (GA has no extension or
 * re-application concept of its own, see GaApplicationWorkflow's own
 * docblock), so the latest one is always the right one, for both the
 * read (approvedFor()) and the write (extendEndDate()) below.
 */
class ApprovedGaApplicationProvider implements SuppliesApprovedGaApplication
{
    public function approvedFor(User $student): ?array
    {
        $application = $this->currentApprovedApplication($student);

        if (! $application) {
            return null;
        }

        $detail = GaApplicationDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return null;
        }

        return ['application' => $application, 'service_end_date' => $detail->service_end_date];
    }

    public function extendEndDate(User $student, Carbon $newEndDate): bool
    {
        $application = $this->currentApprovedApplication($student);

        if (! $application) {
            return false;
        }

        return (bool) GaApplicationDetail::where('application_id', $application->id)
            ->update(['service_end_date' => $newEndDate]);
    }

    protected function currentApprovedApplication(User $student): ?Application
    {
        return Application::where('student_id', $student->id)
            ->where('module_type', 'ga_application')
            ->where('status', Application::STATUS_APPROVED)
            ->latest('submitted_at')
            ->first();
    }
}
