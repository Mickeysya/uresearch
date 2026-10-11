<?php

namespace App\Modules\Norhanis\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Norhanis\Models\PublicationEvaluation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the student what an internal reviewer did with their paper: either a
 * completed Paper Evaluation Sheet, attached as a PDF, or a reviewer who said
 * they cannot do it. $submitted is true when that sheet was the last thing
 * outstanding and the application has gone on to the supervisor.
 */
class PaperEvaluationReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected PublicationEvaluation $evaluation,
        protected ?ApplicationDocument $sheet = null,
        protected bool $submitted = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Publication Application #{$this->application->id}: ".$this->headline())
            ->greeting('Hi '.$notifiable->name.',');

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        $mail->action('Open your sign-off page', route('publication.sign-offs', $this->application))
            ->line('Thank you for using UResearch 2.0.');

        // Guarded: the queue may run this while the file is unreadable, and a
        // missing attachment should not cost the student the notification.
        if ($this->sheet?->exists()) {
            $mail->attach(
                Attachment::fromStorageDisk('local', $this->sheet->path)
                    ->as($this->sheet->original_name)
                    ->withMime($this->sheet->mime_type ?? 'application/pdf'),
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
        $name = $this->evaluation->reviewer_name;

        return $this->evaluation->isDeclined()
            ? "{$name} cannot review your paper"
            : "{$name} evaluated your paper ({$this->evaluation->recommendationLabel()})";
    }

    /** @return array<int, string> */
    protected function lines(): array
    {
        $name = $this->evaluation->reviewer_name;

        if ($this->evaluation->isDeclined()) {
            return [
                "{$name} is unable to review your paper: \"{$this->evaluation->decline_reason}\"",
                'Please choose another reviewer on your sign-off page so your application can continue.',
            ];
        }

        $lines = ["{$name} has completed the Paper Evaluation Sheet. Recommendation: {$this->evaluation->recommendationLabel()}. The signed sheet is attached."];

        $lines[] = match (true) {
            $this->submitted => 'That was the last sign-off outstanding. Your application has been sent to your supervisor.',
            $this->evaluation->recommendation === PublicationEvaluation::CORRECTIONS => 'Please reply to the comments and upload the corrected paper on your sign-off page.',
            $this->evaluation->recommendation === PublicationEvaluation::REJECTED => 'Your application cannot continue with a rejected evaluation. Revise the paper, then ask for a new evaluation on your sign-off page.',
            default => 'Your application goes to your supervisor once every author and reviewer has signed.',
        };

        return $lines;
    }
}
