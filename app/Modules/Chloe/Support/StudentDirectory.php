<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Support\Collection;

/**
 * The one place that knows a "student" might be a real `users` row or a
 * row in our own demo_student_profiles table, and resolves either into
 * the same StudentIdentity shape. Every screen and service that displays
 * or searches for a student goes through here instead of reading
 * student_id / demo_profile_id or the `users` table directly, so the two
 * kinds of student never have to be handled as two different cases at
 * the call site.
 *
 * Reads real users first (gender from student_genders, same single
 * source every other screen already uses and still cannot write to),
 * then the demo profiles table. Never writes either.
 */
final class StudentDirectory
{
    public static function forUserId(?int $userId): ?StudentIdentity
    {
        if (! $userId) {
            return null;
        }

        $user = User::where('id', $userId)->where('role', Role::STUDENT)->first();

        return $user ? self::fromUser($user) : null;
    }

    public static function forDemoId(?int $demoProfileId): ?StudentIdentity
    {
        if (! $demoProfileId) {
            return null;
        }

        $profile = DemoStudentProfile::find($demoProfileId);

        return $profile ? self::fromDemoProfile($profile) : null;
    }

    /**
     * Exactly one of the two is ever set on a WorkstationRequest/LockerKey
     * row -- see those migrations' own doc for why. Null, null (no
     * occupant at all) is a legitimate case too (an Available seat with
     * nobody on it).
     */
    public static function forOccupant(?int $userId, ?int $demoProfileId): ?StudentIdentity
    {
        return self::forUserId($userId) ?? self::forDemoId($demoProfileId);
    }

    /** The one place both "student ID spaces" are checked together -- see WorkstationDemoSeed's own uniqueness check, which calls this. */
    public static function findByStudentId(string $studentId): ?StudentIdentity
    {
        $user = User::where('matric_no', $studentId)->where('role', Role::STUDENT)->first();

        if ($user) {
            return self::fromUser($user);
        }

        $profile = DemoStudentProfile::where('matric_no', $studentId)->first();

        return $profile ? self::fromDemoProfile($profile) : null;
    }

    /**
     * Every real and demo student, unfiltered -- for the suggestions
     * endpoint's fuzzy matching, which has to compare the typed term
     * against every candidate's name itself (a typo like "ahmed" has no
     * substring in common with "Ahmad Danial" for a SQL LIKE to find; see
     * search() below, which is LIKE-filtered and so is the wrong source
     * for this).
     *
     * @return Collection<int, StudentIdentity>
     */
    public static function all(): Collection
    {
        $users = User::where('role', Role::STUDENT)->get()->map(fn (User $user) => self::fromUser($user));
        $profiles = DemoStudentProfile::all()->map(fn (DemoStudentProfile $profile) => self::fromDemoProfile($profile));

        return $users->concat($profiles)->values();
    }

    /**
     * Name, student ID, email and seat number/room code are all searched
     * by the caller against this same collection -- this method itself
     * only has to find candidate students by name/ID/email; a seat/room
     * match is a different kind of row entirely and is handled by the
     * caller (WorkstationManagementController::seatRows()), not here.
     * Plain LIKE-contains, not fuzzy -- see all() above for suggestions'
     * typo-tolerant case.
     *
     * @return Collection<int, StudentIdentity>
     */
    public static function search(string $term, int $limit = 50): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        $users = User::where('role', Role::STUDENT)
            ->where(function ($query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('matric_no', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            })
            ->limit($limit)
            ->get()
            ->map(fn (User $user) => self::fromUser($user));

        $profiles = DemoStudentProfile::where(function ($query) use ($term) {
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('matric_no', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        })
            ->limit($limit)
            ->get()
            ->map(fn (DemoStudentProfile $profile) => self::fromDemoProfile($profile));

        return $users->concat($profiles)->values();
    }

    public static function fromUser(User $user): StudentIdentity
    {
        return new StudentIdentity(
            name: $user->name,
            studentId: (string) $user->matric_no,
            email: $user->email,
            gender: StudentGender::where('student_id', $user->id)->value('gender'),
            programme: $user->programme,
            hasLogin: true,
            user: $user,
        );
    }

    public static function fromDemoProfile(DemoStudentProfile $profile): StudentIdentity
    {
        return new StudentIdentity(
            name: $profile->name,
            studentId: $profile->matric_no,
            email: $profile->email,
            gender: $profile->gender,
            programme: $profile->programme,
            hasLogin: false,
            demoProfile: $profile,
        );
    }
}
