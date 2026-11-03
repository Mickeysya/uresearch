<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\VivaSoftboundDetail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The student's receipt for a softbound thesis (Form #019). */
class VivaSoftboundSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected VivaSoftboundDetail $detail,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Softbound thesis received: '.$this->application->reference())
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your softbound thesis (Form #019) has been received.')
            ->line('Thesis: '.$this->detail->thesis_title)
            ->line('Similarity index: '.$this->detail->similarity_percent.'%')
            ->line('Word count: '.number_format($this->detail->word_count))
            ->line('Your Academic Executive will check it. If anything needs fixing, it will be returned to you with a reason.')
            ->action('Track your submission', route('applications.show', $this->application))
            ->line('Thank you for using UResearch 2.0.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => $this->application->module()->label(),
            'reference' => $this->application->reference(),
            'title' => 'Your softbound thesis has been received.',
            'message' => 'Your Academic Executive will check it against Form #019.',
        ];
    }
}
