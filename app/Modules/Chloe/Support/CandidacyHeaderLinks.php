<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Core\Support\Role;

/**
 * Where "Home" goes on a Study Candidacy page, the same rule Workstation uses (WorkstationHeaderLinks): the landing page of the visitor's own side of the module.
 * Student: My Study Candidacy. Approver: the Approval Portal. CGS: Candidacy Operations. Anyone else (the Registry): the dashboard.
 */
final class CandidacyHeaderLinks
{
    public static function home(): string
    {
        $user = auth()->user();
        $role = $user?->role;

        return match (true) {
            $role === Role::STUDENT => route('candidacy.status'),
            $role !== null && (in_array($role, (array) config('candidacy.cgs_roles'), true) || Role::isCgs($role)) => route('candidacy.cgs.operations'),
            in_array($role, [Role::SUPERVISOR, Role::CHAIR, Role::DEAN_PGR], true) => route('candidacy.portal.queue'),
            default => route('dashboard'),
        };
    }
}
