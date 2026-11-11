<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Every main examiner, internal or external, signs in to My Re-evaluations.
 * The account is matched on email and made when the panel is sent the pack.
 * New accounts use the existing faculty role, which owns no queues, and start
 * with a random password the examiner replaces through a signed link.
 */
final class ReVivaExaminerAccounts
{
    public static function find(Examiner $examiner): ?User
    {
        return $examiner->email ? User::whereRaw('LOWER(email) = ?', [mb_strtolower($examiner->email)])->first() : null;
    }

    /** @return array{0: ?User, 1: bool} the account, and whether it was just created */
    public static function ensure(Examiner $examiner): array
    {
        if (! $examiner->usableEmail()) {
            return [null, false];
        }
        if ($user = self::find($examiner)) {
            return [$user->role === Role::STUDENT ? null : $user, false];
        }

        return [User::create([
            'name' => $examiner->name, 'email' => $examiner->usableEmail(), 'password' => Str::password(40),
            'role' => Role::FACULTY, 'department' => $examiner->department,
        ]), true];
    }

    /** Works once: the signature covers a fingerprint of the current password. */
    public static function setupLink(User $user): string
    {
        return URL::temporarySignedRoute('reviva.account.setup', Carbon::now()->addDays((int) config('hani.reviva.signed_link_days', 35)), [
            'user' => $user->id, 'v' => self::fingerprint($user),
        ]);
    }

    public static function fingerprint(User $user): string
    {
        return substr(hash_hmac('sha256', (string) $user->password, (string) config('app.key')), 0, 16);
    }

    /** @return array<int, int> the examiner records this user is */
    public static function examinerIds(User $user): array
    {
        return Examiner::whereRaw('LOWER(email) = ?', [mb_strtolower($user->email)])->pluck('id')->all();
    }
}
