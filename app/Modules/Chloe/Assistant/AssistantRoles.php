<?php

namespace App\Modules\Chloe\Assistant;

use App\Modules\Chloe\Support\CandidacyStaffDirectory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;

/**
 * Who the assistant answers as. Three role tokens: 'student', 'approver' (Supervisor, Department Chair, Dean) and 'cgs' (candidacy.cgs_roles, the Workstation CGS team).
 * A CGS user who is ALSO named as an approver (they hold a candidacy staff profile) is 'cgs_approver': both sets, and nothing else. Every catalogue entry is tagged with the
 * roles that may see it; a role never receives another role's entries, lookups or topics, whatever the model says (Pipeline re-checks the chosen id against the asking role).
 * Approver data is never reached through a new path: the lookups use CandidacyPortalQuery / CandidacyAppealPolicy's own profile lookups.
 */
final class AssistantRoles
{
    public const STUDENT = 'student';

    public const APPROVER = 'approver';

    public const CGS = 'cgs';

    public const CGS_APPROVER = 'cgs_approver';

    /** The role token of this user, or null when the assistant does not serve them. */
    public static function roleFor(User $user): ?string
    {
        if ($user->role === Role::STUDENT) {
            return self::STUDENT;
        }

        $cgs = Role::isCgs($user->role) || in_array($user->role, (array) config('candidacy.cgs_roles'), true);

        if ($cgs) {
            return CandidacyStaffDirectory::profileForUser($user) ? self::CGS_APPROVER : self::CGS;
        }

        return in_array($user->role, [Role::SUPERVISOR, Role::CHAIR, Role::DEAN_PGR], true) ? self::APPROVER : null;
    }

    /** @return list<string> the plain roles a token stands for */
    public static function set(string $role): array
    {
        return match ($role) {
            self::CGS_APPROVER => [self::CGS, self::APPROVER],
            default => [$role],
        };
    }

    /** @param array<int,string> $entryRoles */
    public static function covers(string $role, array $entryRoles): bool
    {
        return array_intersect(self::set($role), $entryRoles) !== [];
    }

    public static function hasCgs(string $role): bool
    {
        return in_array(self::CGS, self::set($role), true);
    }
}
