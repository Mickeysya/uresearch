<?php

namespace App\Modules\Norhanis\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The student's receipt for a Travel or Claims submission: it went through,
 * and who has it now. Publication sends its own, from the sign-off emails.
 */
class ApplicationSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Application $application) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $stage = $this->application->currentStage();

        return (new MailMessage)
            ->subject("{$this->module()} Application #{$this->application->id}: Submitted")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("Your {$this->module()} application has been submitted.")
            ->line('It is now with: '.($stage?->label ?? 'the first approver').'. You will be emailed after each decision.')
            ->action('Track your application', route('applications.show', $this->application))
            ->line('Thank you for using UResearch 2.0.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => $this->module(),
            'title' => "Your {$this->module()} application has been submitted.",
            'next_stage' => $this->application->currentStage()?->label,
        ];
    }

    protected function module(): string
    {
        return $this->application->module()->label();
    }
}
