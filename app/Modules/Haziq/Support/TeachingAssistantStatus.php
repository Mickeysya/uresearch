<?php

namespace App\Modules\Haziq\Support;

use App\Modules\Core\Contracts\SuppliesTeachingAssistantStatus;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraApplicationDetail;
use Illuminate\Support\Carbon;

/**
 * Answers Core\Contracts\SuppliesTeachingAssistantStatus from this module's
 * own records, for other modules' forms (Norhanis' Travel TA plan).
 *
 * An appointment counts when its application is approved, its candidature
 * is still active, and today falls inside its service period -- the same
 * "approved and not terminated" reading the allowance screens use.
 */
class TeachingAssistantStatus implements SuppliesTeachingAssistantStatus
{
    public function currentAppointmentFor(User $student): ?array
    {
        // GA first: it is the appointment that carries teaching duties.
        foreach (['GA' => GaApplicationDetail::class, 'GRA' => GraApplicationDetail::class] as $type => $model) {
            $detail = $model::query()
                ->whereHas('application', fn ($q) => $q
                    ->where('student_id', $student->id)
                    ->where('status', Application::STATUS_APPROVED))
                ->where('candidature_status', 'active')
                ->whereDate('service_start_date', '<=', Carbon::today())
                ->where(fn ($q) => $q->whereNull('service_end_date')->orWhereDate('service_end_date', '>=', Carbon::today()))
                ->orderBy('service_start_date')
                ->first();

            if ($detail) {
                return ['type' => $type, 'since' => Carbon::parse($detail->service_start_date)];
            }
        }

        return null;
    }

}
