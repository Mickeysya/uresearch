<?php

namespace App\Modules\Chloe\Notifications;

use App\Modules\Chloe\Models\LockerKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Every locker-key status email + bell pair a student gets (requested,
 * the one reminder, auto-cancelled) in one class, not four — they all
 * carry the same seat/block/room/collect-by details and the same
 * signature, so the only thing that actually varies between them is
 * which lines get printed. Collected is bell-only (rule 10: no email).
 *
 * Renamed from the old single-purpose LockerKeyReminder, which this
 * replaces — see Console\Commands\RemindOverdueLockerKeys.
 */
class LockerKeyNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const REQUESTED = 'requested';

    public const REMINDER = 'reminder';

    public const CANCELLED = 'cancelled';

    public const COLLECTED = 'collected';

    public const RETURN_REMINDER = 'return_reminder';

    public function __construct(protected LockerKey $lockerKey, protected string $kind) {}

    /**
     * A demo student (StudentIdentity::notify(), when there is no real
     * login) routes through Notification::route('mail', ...), which hands
     * this an AnonymousNotifiable — one with no `notifications()` relation
     * to write a bell row against, so 'database' is dropped for it rather
     * than fatally erroring on every demo-seat action.
     */
    public function via(object $notifiable): array
    {
        $channels = $this->kind === self::COLLECTED ? ['database'] : ['mail', 'database'];

        if ($notifiable instanceof AnonymousNotifiable) {
            $channels = array_values(array_diff($channels, ['database']));
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $seat = $this->lockerKey->workstationRequest->workstation;
        $location = $seat->location;
        $collectBy = $this->lockerKey->collectByDate()->format('j M Y');

        $message = match ($this->kind) {
            self::REQUESTED => (new MailMessage)
                ->subject('Locker Key Request Received')
                ->greeting('Dear '.$this->lockerKey->occupant()?->name.',')
                ->line('Your locker key request has been received for the following workstation:')
                ->line('Block: '.$location->block)
                ->line('Room: '.$location->room_code)
                ->line('Seat: '.$seat->seat_code)
                ->line('Please collect your locker key at the Centre for Graduate Studies (CGS), Monday to Friday, 8:00 AM - 5:00 PM (GMT +8.00), on or before '.$collectBy.'.')
                ->line('If the key is not collected by this date, your request will be cancelled automatically. You may submit a new request through the dashboard at any time.'),

            self::REMINDER => (new MailMessage)
                ->subject('Reminder: Please Collect Your Locker Key')
                ->greeting('Dear '.$this->lockerKey->occupant()?->name.',')
                ->line('This is a reminder that your locker key for Seat '.$seat->seat_code.' (Room '.$location->room_code.', Block '.$location->block.') has not been collected yet.')
                ->line('Please collect it at the Centre for Graduate Studies (CGS), Monday to Friday, 8:00 AM - 5:00 PM (GMT +8.00), on or before '.$collectBy.'. If the key is not collected by this date, your request will be cancelled automatically.'),

            self::CANCELLED => (new MailMessage)
                ->subject('Locker Key Request Cancelled')
                ->greeting('Dear '.$this->lockerKey->occupant()?->name.',')
                ->line('Your locker key request for Seat '.$seat->seat_code.' (Room '.$location->room_code.', Block '.$location->block.') has been cancelled automatically because the key was not collected within 7 days.')
                ->line('Your workstation allocation is not affected. If you still need the key, you may submit a new request through the dashboard.'),

            self::RETURN_REMINDER => (new MailMessage)
                ->subject('Reminder: Please Return Your Locker Key')
                ->greeting('Dear '.$this->lockerKey->occupant()?->name.',')
                ->line('This is a reminder that the locker key for Seat '.$seat->seat_code.' (Room '.$location->room_code.', Block '.$location->block.') has not been returned yet. Please return it to the Centre for Graduate Studies (CGS), Monday to Friday, 8:00 AM - 5:00 PM (GMT +8.00), on or before '.$this->lockerKey->return_due_at->format('j M Y').'.'),
        };

        return $message->salutation("Best regards,\nCentre for Graduate Studies (CGS)\nUniversiti Teknologi PETRONAS");
    }

    public function toArray(object $notifiable): array
    {
        $collectBy = $this->lockerKey->collectByDate()->format('j M Y');

        return ['title' => match ($this->kind) {
            self::REQUESTED => 'Locker key request received',
            self::REMINDER => 'Reminder: please collect your locker key by '.$collectBy,
            self::CANCELLED => 'Your locker key request was cancelled (not collected within 7 days)',
            self::COLLECTED => 'Your locker key has been collected',
            self::RETURN_REMINDER => 'Reminder: please return your locker key by '.$this->lockerKey->return_due_at->format('j M Y'),
        }];
    }
}
