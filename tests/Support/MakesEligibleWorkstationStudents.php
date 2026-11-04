<?php

namespace Tests\Support;

use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Core\Models\User;

/**
 * PART 1: WorkstationEligibility requires Active status, a full-time
 * research `programme` string, and a future candidacy_expiry_date --
 * MakesUsers::student() (shared across every module's tests, not just
 * Chloe's) deliberately sets none of that, so a Workstation test whose
 * student actually needs to browse or hold a seat asks for one here
 * instead, rather than that requirement being bolted onto the shared
 * helper for every other module's tests too.
 */
trait MakesEligibleWorkstationStudents
{
    protected function eligibleStudent(string $matric = '22001001', string $email = 'student@test.my', array $attributes = []): User
    {
        $student = $this->student($matric, $email, $attributes + [
            'programme' => 'MSc Computer Science (Full-Time)',
        ]);

        StudyCandidacy::create([
            'student_id' => $student->id,
            'programme_start_date' => now()->subMonths(12),
            'candidacy_expiry_date' => now()->addMonths(12),
            'status' => StudyCandidacy::STATUS_ACTIVE,
        ]);

        return $student;
    }
}
