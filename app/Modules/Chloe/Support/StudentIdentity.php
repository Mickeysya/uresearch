<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Core\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * One student, real or demo, as every screen and service actually needs
 * to display and act on them: a name, a student ID, an email, a gender,
 * a programme, and whether there is a real login behind it. Built only by
 * Support\StudentDirectory -- nothing else constructs one directly.
 */
final class StudentIdentity
{
    public function __construct(
        public readonly string $name,
        public readonly string $studentId,
        public readonly string $email,
        public readonly ?string $gender,
        public readonly ?string $programme,
        public readonly bool $hasLogin,
        public readonly ?User $user = null,
        public readonly ?DemoStudentProfile $demoProfile = null,
    ) {}

    /**
     * A real student gets the normal ->notify() (mail + database, per each
     * notification's own via()); a demo one has no Eloquent model to call
     * that on at all, so this routes through Notification::route(), which
     * hands the notification an AnonymousNotifiable -- see
     * WorkstationStatusNotice/LockerKeyNotice's own via() for why that
     * safely drops the 'database' channel instead of erroring.
     */
    public function notify(Notification $notification): void
    {
        if ($this->user) {
            $this->user->notify($notification);

            return;
        }

        NotificationFacade::route('mail', $this->email)->notify($notification);
    }

    /**
     * For WorkstationHistory::record()'s `?User $student` snapshot
     * parameter only -- it reads nothing but ->name and ->matric_no, never
     * saves or notifies the object, so an unsaved, never-persisted User
     * standing in for a demo profile is safe: it writes nothing to the
     * shared `users` table.
     */
    public function asUserLike(): User
    {
        return $this->user ?? new User([
            'name' => $this->name,
            'matric_no' => $this->studentId,
            'email' => $this->email,
            'programme' => $this->programme,
        ]);
    }
}
