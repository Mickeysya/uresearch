<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Core\Support\Role;

/**
 * Where the "← Back" fallback and the blue "Home" button of the Workstation
 * header pattern (x-core::page-header) lead, for pages that anyone can open
 * -- the Term of Use and the email-link result pages -- and so must work
 * for a guest, a student, CGS staff and any other signed-in role.
 *
 * Home uses the targets the module's own pages already use: a student's
 * pages go to workstation.select, CGS pages to workstation.cgs.operations.
 * A guest gets workstation.select too: it is behind login, so a guest lands
 * on the sign-in page (no route is invented). Any other signed-in role has
 * neither workstation home, so it goes to the portal dashboard.
 *
 * back is null for a guest -- there is no previous page of ours to go back
 * to, so the link is not shown at all.
 */
final class WorkstationHeaderLinks
{
    /** @return array{home: string, back: ?string} */
    public static function forCurrentVisitor(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return ['home' => route('workstation.select'), 'back' => null];
        }

        $target = match (true) {
            $user->role === Role::STUDENT => route('workstation.select'),
            Role::isCgs($user->role) => route('workstation.cgs.operations'),
            default => route('dashboard'),
        };

        return ['home' => $target, 'back' => $target];
    }
}
