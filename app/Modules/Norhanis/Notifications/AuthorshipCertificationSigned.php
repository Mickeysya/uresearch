<?php

namespace App\Modules\Norhanis\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the student that a co-author has signed the Authorship Certification.
 *
 * When that signature was the last one outstanding, $form is the generated
 * PDF: it is attached, and the message says the application has gone on to
 * the supervisor.
 */
class AuthorshipCertificationSigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected string $authorName,
        protected int $signed,
        protected int $total,
        protected ?ApplicationDocument $form = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Publication Application #{$this->application->id}: ".$this->headline())
            ->greeting('Hi '.$notifiable->name.',')
            ->line("{$this->authorName} has signed the Authorship Certification ({$this->signed} of {$this->total} authors).");

        if ($this->form === null) {
            return $mail
                ->line('Your application will go to your supervisor once every author has signed.')
                ->action('See who has signed', route('publication.sign-offs', $this->application))
                ->line('Thank you for using UResearch 2.0.');
        }

        $mail->line('Every author has now signed. The completed form is attached, and your application has been sent to your supervisor.')
            ->action('Track your application', route('applications.show', $this->application))
            ->line('Thank you for using UResearch 2.0.');

        // Guarded for the same reason as Nureen's CertificationIssued: a
        // missing file should not cost the student the notification.
        if ($this->form->exists()) {
            $mail->attachFromStorageDisk(
                'local',
                $this->form->path,
                $this->form->original_name,
                ['mime' => $this->form->mime_type ?? 'application/pdf'],
            );
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => 'Publication',
            'title' => "Publication Application #{$this->application->id}: ".$this->headline(),
            'url' => route('publication.sign-offs', $this->application),
        ];
    }

    protected function headline(): string
    {
        return $this->form === null
            ? "{$this->authorName} signed ({$this->signed} of {$this->total})"
            : 'all authors have signed';
    }
}
