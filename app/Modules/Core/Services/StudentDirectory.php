<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\SuppliesSupervisionHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see which students on the Students page (/students), in one place
 * so the list, the record and the tests can never disagree:
 *
 *   CGS team (Non-Executive, Senior Executive, Manager, Senior Director),
 *   Dean of PGR, Admin            every student
 *   Chair of Department           students of their own department
 *   Supervisor                    students they supervise: main supervisor
 *                                 (users.supervisor_id) or an active
 *                                 co-supervisor (SuppliesSupervisionHistory)
 *   anyone else, students too     nobody (the route refuses them)
 *
 * The projected attendance figure is CGS-only: seesProjection().
 */
class StudentDirectory
{
    /** Every role the Students page is open to. */
    public static function roles(): array
    {
        return [...Role::cgsTeam(), Role::DEAN_PGR, Role::ADMIN, Role::CHAIR, Role::SUPERVISOR];
    }

    public static function seesProjection(User $viewer): bool
    {
        return Role::isCgs($viewer->role);
    }

    /** The students $viewer may see, as a query on users. */
    public function visibleTo(User $viewer): Builder
    {
        $query = User::query()->where('users.role', Role::STUDENT);

        return match (true) {
            Role::isCgs($viewer->role), in_array($viewer->role, [Role::DEAN_PGR, Role::ADMIN], true) => $query,
            $viewer->role === Role::CHAIR => $viewer->department
                ? $query->where('users.department', $viewer->department)
                : $query->whereRaw('1 = 0'),
            $viewer->role === Role::SUPERVISOR => $query->where(function (Builder $q) use ($viewer) {
                $q->where('users.supervisor_id', $viewer->id);

                if (app()->bound(SuppliesSupervisionHistory::class)) {
                    $q->orWhereIn('users.id', app(SuppliesSupervisionHistory::class)->coSupervisedStudentIds($viewer));
                }
            }),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function canView(User $viewer, User $student): bool
    {
        return $this->visibleTo($viewer)->whereKey($student->id)->exists();
    }
}
