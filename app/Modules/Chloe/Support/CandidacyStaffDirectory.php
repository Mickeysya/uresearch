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

    /** The department's own dean, or the default dean when it has none. */
    public static function dean(CandidacyDepartment|int|null $department): ?CandidacyStaffProfile
    {
        return ($department ? self::holders($department, CandidacyStaffAssignment::ROLE_DEAN)->first() : null) ?? self::defaultDean();
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
            ->orderBy('full_name')
            ->get();
    }
}
