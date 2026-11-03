<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerContactHistory;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Notifications\ExaminerEmailReported;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The one place an examiner's email is changed, reported or confirmed.
 * Every change is logged in examiner_contact_history with the old and new
 * address, who and why, so the examiner page can show where an address
 * came from.
 *
 * A reported address is Invalid until someone saves a new one: nothing
 * emails the examiner meanwhile (Examiner::usableEmail()), and saving any
 * new address clears the flag.
 */
class ExaminerContact
{
    /**
     * Sets the email and logs it. Nothing is logged when the address is the
     * same (ignoring case). Uniqueness is the caller's validation.
     *
     * @return bool whether the address changed
     */
    public function changeEmail(Examiner $examiner, ?string $email, ?User $by, string $source, ?string $reason = null): bool
    {
        $email = filled($email) ? mb_strtolower(trim($email)) : null;
        $old = $examiner->email;

        if ($email === ($old !== null ? mb_strtolower($old) : null)) {
            return false;
        }

        DB::transaction(function () use ($examiner, $email, $old, $by, $source, $reason) {
            $examiner->update([
                'email' => $email,
                'email_status' => Examiner::EMAIL_OK,
                'email_flagged_at' => null,
            ]);
            $this->log($examiner, $old, $email, $by, $source, $reason);
        });

        return true;
    }

    /**
     * "Email not working": flags the address Invalid, logs who said so and
     * why, and tells Non-Executive and Senior Executive CGS, who keep the
     * pool's emails. Reporting an address already flagged only adds the line.
     */
    public function report(Examiner $examiner, User $by, string $reason): void
    {
        $already = $examiner->emailInvalid();

        DB::transaction(function () use ($examiner, $by, $reason, $already) {
            if (! $already) {
                $examiner->update(['email_status' => Examiner::EMAIL_INVALID, 'email_flagged_at' => now()]);
            }

            $this->log($examiner, $examiner->email, $examiner->email, $by, 'reported', $reason);
        });

        if ($already) {
            return;
        }

        try {
            Notification::send(
                User::whereIn('role', [Role::NON_EXEC_CGS, Role::SENIOR_EXEC_CGS])->where('id', '!=', $by->id)->get(),
                new ExaminerEmailReported($examiner, $by, $reason),
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * CGS for anyone; an Academic Executive for an internal examiner in
     * their own department, or anyone sitting on a panel for one of their
     * department's students (an external examiner has no UTP department).
     */
    public function canReport(User $user, Examiner $examiner): bool
    {
        if (in_array($user->role, [Role::NON_EXEC_CGS, Role::SENIOR_EXEC_CGS], true)) {
            return true;
        }

        if ($user->role !== Role::ACADEMIC_EXEC || blank($user->department)) {
            return false;
        }

        if (! $examiner->isExternal() && app(Departments::class)->same($examiner->department, $user->department)) {
            return true;
        }

        return ExaminerNomination::query()
            ->where(function ($q) use ($examiner) {
                foreach (array_keys(ExaminerNomination::SLOTS) as $slot) {
                    $q->orWhere($slot, $examiner->id);
                }
            })
            ->whereHas('application', fn ($q) => $q->where('status', '!=', Application::STATUS_REJECTED)
                ->whereHas('student', fn ($s) => $s->where('department', $user->department)))
            ->exists();
    }

    /** A line in the history for a change already made elsewhere (the pool import). */
    public function log(Examiner $examiner, ?string $old, ?string $new, ?User $by, string $source, ?string $reason): ExaminerContactHistory
    {
        return $examiner->contactHistory()->create([
            'old_email' => $old,
            'new_email' => $new,
            'source' => $source,
            'reason' => filled($reason) ? $reason : null,
            'changed_by_id' => $by?->id,
        ]);
    }
}
