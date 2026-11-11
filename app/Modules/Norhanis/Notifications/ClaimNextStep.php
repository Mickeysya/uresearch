<?php

namespace App\Modules\Norhanis\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Support\ClaimablePublications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the student, once, when a journal publication on "CGS – Pay and
 * Claims" is finally approved: the claim is theirs to file next, with a
 * button that opens the claim form with this publication already chosen.
 * Sent by Support\PublicationFollowUp, beside Core's "fully approved" email.
 */
class ClaimNextStep extends Notification implements ShouldQueue
{
    use Queueable;

    public const LINE = 'Next step: you can now submit your claim for this publication in the Student Claims application in UResearch 2.0.';

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
            ->line('Your Publication application has been approved.')
            ->line(self::LINE)
            ->action('Start your claim', ClaimablePublications::claimUrl($this->application->id))
            ->line('Thank you for using UResearch 2.0.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => 'Publication',
            'title' => $this->subject(),
            'url' => ClaimablePublications::claimUrl($this->application->id),
        ];
    }

    protected function subject(): string
    {
        return "Publication Application #{$this->application->id}: Submit your claim";
    }
}
