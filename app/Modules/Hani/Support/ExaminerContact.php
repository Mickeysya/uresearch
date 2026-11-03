<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerContactHistory;
use Illuminate\Support\Facades\DB;

/**
 * The one place an examiner's email is changed. Every change is logged in
 * examiner_contact_history with the old and new address, who and why, so
 * the examiner page can show where an address came from.
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
            $examiner->update(['email' => $email]);
            $this->log($examiner, $old, $email, $by, $source, $reason);
        });

        return true;
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
