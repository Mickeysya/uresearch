<?php

namespace App\Modules\Chloe\Notifications;

use App\Modules\Chloe\Models\Workstation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Every seat-lifecycle email + bell a student gets (released, returned,
 * changed, assigned, the 14/7/3-day release notice) in one class, same
 * reasoning as LockerKeyNotice for the key side: they share the seat
 * details, the signature, and (released/returned/changed) the "key
 * paragraph" -- splitting them would just be the same boilerplate five
 * times over. Collected/return-reminder stay on LockerKeyNotice; this one
 * is the seat, not the key.
 *
 * $context keys used per kind:
 *   RELEASED/RETURNED/CHANGED: 'reason' (string), 'key_paragraph' (null|'collected'|'pending')
 *   CHANGED only: 'previous_seat' (string, e.g. "N2-02-01 Seat 5")
 *   RELEASE_NOTICE: 'release_date' (Carbon), 'days_left' (int)
 *   TERMINATION_NOTICE: 'reason' (string), 'notice_date' (Carbon), 'vacate_by_date' (Carbon), 'terms_link' (string)
 *   TERMINATION_WITHDRAWN: 'notice_date' (Carbon)
 */
class WorkstationStatusNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const RELEASED = 'released';

    public const RETURNED = 'returned';

    public const CHANGED = 'changed';

    public const ASSIGNED = 'assigned';

    /** Superseded by TERMINATION_NOTICE (PART 3 replaced the old 14/7/3-day pipeline) -- kept only so the class still compiles if anything historical refers to it; nothing sends it any more. */
    public const RELEASE_NOTICE = 'release_notice';

    /** PART 3/6: the 5-working-day notice. Text is verbatim from the Term of Use task spec -- do not reword without checking it against that spec again. */
    public const TERMINATION_NOTICE = 'termination_notice';

    public const TERMINATION_WITHDRAWN = 'termination_withdrawn';

    /**
     * $studentName is captured at the moment the notification is built,
     * not re-derived from $notifiable inside toMail() -- it has to work
     * for an AnonymousNotifiable (a demo student, no ->name property at
     * all) exactly as well as for a real User, and by the time a queued
     * notification is actually processed the seat's own occupant may
     * already have changed again.
     */
    public function __construct(
        protected Workstation $seat,
        protected string $kind,
        protected array $context = [],
        protected ?string $studentName = null,
    ) {}

    /**
     * A demo student (StudentIdentity::notify(), when there is no real
     * login) routes through Notification::route('mail', ...), which hands
     * this an AnonymousNotifiable -- one with no `notifications()`
     * relation to write a bell row against, so 'database' is dropped for
     * it rather than fatally erroring on every demo-seat action.
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return ['mail'];
        }

        return ['mail', 'database'];
    }

    protected function keyParagraph(): ?string
    {
        return match ($this->context['key_paragraph'] ?? null) {
            'collected' => 'Our records show that you have collected the locker key for the previous seat. Please return it to the Centre for Graduate Studies (CGS), Monday to Friday, 8:00 AM - 5:00 PM (GMT +8.00), on or before '.$this->context['return_due_date'].'.',
            'pending' => 'Your pending locker key request for this seat has been cancelled.',
            default => null,
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $location = $this->seat->location;
        $message = (new MailMessage)->greeting('Dear '.($this->studentName ?? $notifiable->name).',');

        $message = match ($this->kind) {
            self::RELEASED => $message
                ->subject('Workstation Released')
                ->line('Your workstation allocation has been released by the Centre for Graduate Studies (CGS):')
                ->line('Block: '.$location->block)
                ->line('Room: '.$location->room_code)
                ->line('Seat: '.$this->seat->seat_code)
                ->line('Reason: '.$this->context['reason'])
                ->when($this->keyParagraph(), fn ($m, $p) => $m->line($p))
                ->line('You may select a new workstation through the dashboard at any time. If you believe this is a mistake, please contact CGS.'),

            self::RETURNED => $message
                ->subject('Workstation Returned')
                ->line('You have returned the following workstation:')
                ->line('Block: '.$location->block)
                ->line('Room: '.$location->room_code)
                ->line('Seat: '.$this->seat->seat_code)
                ->line('Reason: '.$this->context['reason'])
                ->when($this->keyParagraph(), fn ($m, $p) => $m->line($p))
                ->line('You may select a new workstation through the dashboard at any time.'),

            self::CHANGED => $message
                ->subject('Workstation Changed')
                ->line('Your workstation has been changed by the Centre for Graduate Studies (CGS). No further action is needed to confirm it.')
                ->line('New workstation:')
                ->line('Block: '.$location->block)
                ->line('Room: '.$location->room_code)
                ->line('Seat: '.$this->seat->seat_code)
                ->line('Your previous seat was '.$this->context['previous_seat'].'.')
                ->line('Reason: '.$this->context['reason'])
                ->when($this->keyParagraph(), fn ($m, $p) => $m->line($p))
                ->line('To request the locker key for your new seat, please use the dashboard. Please refer to the seat layout displayed in the room to locate your seat.')
                // PART 4 #2.
                ->line('By using this workstation you agree to the Postgraduate Workstation Term of Use ('.route('workstation.terms').').'),

            self::ASSIGNED => $message
                ->subject('Workstation Assigned')
                ->line('A workstation has been assigned to you by the Centre for Graduate Studies (CGS). No further action is needed to confirm it.')
                ->line('Block: '.$location->block)
                ->line('Room: '.$location->room_code)
                ->line('Seat: '.$this->seat->seat_code)
                ->line('To request your locker key, please use the dashboard. Please refer to the seat layout displayed in the room to locate your seat.')
                // PART 4 #2.
                ->line('By using this workstation you agree to the Postgraduate Workstation Term of Use ('.route('workstation.terms').').'),

            self::RELEASE_NOTICE => $message
                ->subject('Workstation Release Notice: '.$this->context['days_left'].' Days Remaining')
                ->line('Your workstation allocation will end on '.$this->context['release_date'].' ('.$this->context['days_left'].' days from now):')
                ->line('Block: '.$location->block)
                ->line('Room: '.$location->room_code)
                ->line('Seat: '.$this->seat->seat_code)
                ->line('Please vacate the workstation and return your locker key (if you have collected it) to the Centre for Graduate Studies (CGS), Monday to Friday, 8:00 AM - 5:00 PM (GMT +8.00), on or before this date. The workstation will be released automatically on '.$this->context['release_date'].'.'),

            self::TERMINATION_NOTICE => $message
                ->subject('Workstation Loan Terminated: Please Vacate by '.$this->context['vacate_by_date'])
                ->line('Your postgraduate workstation loan has been terminated in accordance with the Postgraduate Workstation Term of Use ('.$this->context['terms_link'].').')
                ->line('Block: '.$location->block)
                ->line('Room: '.$location->room_code)
                ->line('Seat: '.$this->seat->seat_code)
                ->line('Reason: '.$this->context['reason'])
                ->line('Date of notice: '.$this->context['notice_date'])
                ->line('Please vacate the workstation within 5 working days of this notice, on or before '.$this->context['vacate_by_date'].'. If you have collected the locker key, please return it to the Centre for Graduate Studies (CGS), Monday to Friday, 8:00 AM - 5:00 PM (GMT +8.00), on or before the same date. The workstation will be released automatically after this date.'),

            self::TERMINATION_WITHDRAWN => $message
                ->subject('Workstation Termination Notice Withdrawn')
                ->line('The termination notice issued on '.$this->context['notice_date'].' for the following workstation has been withdrawn. You may continue to use it.')
                ->line('Block: '.$location->block)
                ->line('Room: '.$location->room_code)
                ->line('Seat: '.$this->seat->seat_code),
        };

        return $message->salutation("Best regards,\nCentre for Graduate Studies (CGS)\nUniversiti Teknologi PETRONAS");
    }

    public function toArray(object $notifiable): array
    {
        $title = match ($this->kind) {
            self::RELEASED => 'Your workstation has been released',
            self::RETURNED => 'You have returned your workstation',
            self::CHANGED => 'Your workstation has been changed to Seat '.$this->seat->seat_code,
            self::ASSIGNED => 'A workstation has been assigned to you: Seat '.$this->seat->seat_code,
            self::RELEASE_NOTICE => 'Workstation release notice: your workstation will end on '.$this->context['release_date'],
            self::TERMINATION_NOTICE => 'Your workstation loan has been terminated. Please vacate by '.$this->context['vacate_by_date'],
            self::TERMINATION_WITHDRAWN => 'Your workstation termination notice has been withdrawn',
        };

        return ['title' => $title];
    }
}
