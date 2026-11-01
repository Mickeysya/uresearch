<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\CandidacyStaffAssignment;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Support\Collection;

/** Who is the lecturer / chair / dean of a department, and which profile a logged-in user is. */
final class CandidacyStaffDirectory
{
    public static function profileForUser(User $user): ?CandidacyStaffProfile
    {
        return CandidacyStaffProfile::where('user_id', $user->id)->first();
    }

    /** @return Collection<int, CandidacyStaffProfile> */
    public static function lecturers(CandidacyDepartment|int $department): Collection
    {
        return self::holders($department, CandidacyStaffAssignment::ROLE_LECTURER);
    }

    public static function chair(CandidacyDepartment|int $department): ?CandidacyStaffProfile
    {
        return self::holders($department, CandidacyStaffAssignment::ROLE_CHAIR)->first();
    }

    /**
     * The ONE Dean of the whole university (Dean of PGR / CGS Dean): the profile of the account that has the Dean role. A department has no
     * Dean of its own, so the argument is ignored; it stays only so existing callers keep working.
     */
    public static function dean(CandidacyDepartment|int|null $department = null): ?CandidacyStaffProfile
    {
        return self::defaultDean();
    }

    public static function defaultDean(): ?CandidacyStaffProfile
    {
        if ($id = config('candidacy.default_dean_profile')) {
            return CandidacyStaffProfile::find($id);
        }

        $user = User::where('role', Role::DEAN_PGR)->orderBy('id')->first();

        return $user ? self::profileForUser($user) : null;
    }

    /** @return Collection<int, CandidacyStaffProfile> */
    protected static function holders(CandidacyDepartment|int $department, string $role): Collection
    {
        $id = $department instanceof CandidacyDepartment ? $department->id : $department;

        return CandidacyStaffProfile::query()
            ->whereIn('id', CandidacyStaffAssignment::where('department_id', $id)->where('role', $role)->select('staff_profile_id'))
            ->get()
            ->sortBy(fn (CandidacyStaffProfile $p) => mb_strtolower(self::sortName($p->full_name)))
            ->values();
    }

    /** The name without its leading titles ("Assoc. Prof. Ir. Dr. Tan Wei Ming" -> "Tan Wei Ming"), so lists sort by the name itself. */
    public static function sortName(string $name): string
    {
        return trim(preg_replace('/^((assoc\.?\s+prof\.?|prof\.?|ir\.?|dr\.?|puan|encik|en\.?|ts\.?)\s+)+/i', '', $name));
    }
}
