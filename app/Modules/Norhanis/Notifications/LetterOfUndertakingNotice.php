<?php

namespace App\Modules\Norhanis\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the student, once, when a journal publication on "CGS – Letter of
 * Undertaking" is finally approved: CGS pays directly, so there is nothing
 * to claim. Sent by Support\PublicationFollowUp.
 */
class LetterOfUndertakingNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const LINE = 'CGS will process the payment directly. No claim is needed.';

    public function __construct(protected Application $application) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your Publication application has been approved under a Letter of Undertaking (LOU).')
            ->line(self::LINE)
            ->action('View your application', route('applications.show', $this->application))
            ->line('Thank you for using UResearch 2.0.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => 'Publication',
            'title' => $this->subject(),
            'url' => route('applications.show', $this->application),
        ];
    }

    protected function subject(): string
    {
        return "Publication Application #{$this->application->id}: CGS will pay directly";
    }
}
