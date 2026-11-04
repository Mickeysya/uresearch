<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;

/**
 * Can this student's GA Extension or Certification request reach someone?
 *
 * Both start at the student's own named supervisor (snapshotted on the
 * detail row and used to scope that supervisor's queue), so submitting is
 * blocked -- with a message the student can act on -- when there is no
 * supervisor on record, or the account on record can no longer act as one.
 * Shown on the form before the student fills it in, and enforced again on
 * submit. CGS fixes the cause; see Support\StuckSupervisorStages for
 * requests already stuck.
 */
class SupervisorCheck
{
    /** Why this student cannot submit yet, or null when they can. */
    public static function problemFor(User $student): ?string
    {
        if (! $student->supervisor_id) {
            return 'You can’t submit this yet: no supervisor is assigned to your account. '
                .'Please contact CGS to have one assigned.';
        }

        $supervisor = $student->relationLoaded('supervisor') ? $student->supervisor : User::find($student->supervisor_id);

        if (! $supervisor || $supervisor->role !== Role::SUPERVISOR) {
            return 'You can’t submit this yet: your supervisor on record'
                .($supervisor ? " ({$supervisor->name})" : '')
                .' can’t review applications in UResearch, because their account does not have the Supervisor role. '
                .'Please contact CGS to correct your supervisor.';
        }

        return null;
    }
}
