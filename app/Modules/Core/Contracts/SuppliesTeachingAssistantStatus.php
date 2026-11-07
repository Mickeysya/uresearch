<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\User;
use Carbon\CarbonInterface;

/**
 * Implemented by whichever module owns GA/GRA appointments (Haziq's);
 * consumed by any form that needs to know whether a student is a GA/GRA
 * today -- Norhanis' Travel form, which asks for a TA plan from GA/GRA
 * students only. (The TA hours log itself is Norhanis' own.)
 *
 * Same inverted-dependency shape as SuppliesPostgraduateProgress: Core
 * declares the question, the owning module answers it, and the asking
 * module never reads another module's tables.
 *
 * BINDING IS OPTIONAL. A consumer must check app()->bound() first and fall
 * back to asking the student when nothing answers.
 *
 *     $this->app->bind(SuppliesTeachingAssistantStatus::class, YourProvider::class);
 */
interface SuppliesTeachingAssistantStatus
{
    /**
     * The student's current GA or GRA appointment, or null when they hold
     * neither today. A student with both is reported as a GA, the one with
     * teaching duties.
     *
     * @return array{type: 'GA'|'GRA', since: CarbonInterface}|null
     */
    public function currentAppointmentFor(User $student): ?array;
}
