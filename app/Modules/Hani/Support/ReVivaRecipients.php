<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Support\Collection;

/**
 * Who a re-viva email TO CGS goes to (UAT 11 Nov 2026), and nobody else:
 *
 *   hani.reviva.cgs_recipients   Puan Waheda (the Senior Executive who runs
 *                                the re-viva) and En Syahmi (read-only)
 *   + the Academic Executive(s) of the student's department (informational)
 *
 * Only the Senior Executive's copy carries the action button. En Syahmi's
 * points at Re-viva Cycles, which he can open read-only; the AE has no
 * re-viva pages, so the AE's copy has no link (see ReVivaUpdate::forCgs()).
 */
final class ReVivaRecipients
{
    /** @return Collection<int, User> */
    public static function cgs(Application $application): Collection
    {
        $department = $application->student?->department;

        return self::configured()
            ->concat($department ? User::where('role', Role::ACADEMIC_EXEC)->where('department', $department)->get() : [])
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, User> the accounts behind hani.reviva.cgs_recipients, in config order */
    public static function configured(): Collection
    {
        $emails = array_map('strtolower', (array) config('hani.reviva.cgs_recipients', []));
        $users = User::whereIn('email', $emails)->get()->keyBy(fn (User $u) => strtolower($u->email));

        return collect($emails)->map(fn ($email) => $users->get($email))->filter()->values();
    }

    /** The Senior Executive who runs the re-viva: the first configured address, else the first Senior Executive. */
    public static function seniorExec(): ?User
    {
        return self::configured()->first(fn (User $u) => $u->role === Role::SENIOR_EXEC_CGS)
            ?? User::where('role', Role::SENIOR_EXEC_CGS)->orderBy('id')->first();
    }

    public static function isRecipient(User $user, Application $application): bool
    {
        return self::cgs($application)->contains(fn (User $u) => $u->is($user));
    }
}
